<?php
namespace App\Sync\Task;

use App\Entity;
use App\Event\Radio\AnnotateNextSong;
use App\EventDispatcher;
use App\Radio\Adapters;
use App\Radio\Backend\Liquidsoap;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class RadioRequests extends AbstractTask
{
    protected Adapters $adapters;

    protected EventDispatcher $dispatcher;

    protected Entity\Repository\StationRequestRepository $requestRepo;

    public function __construct(
        EntityManagerInterface $em,
        Entity\Repository\SettingsRepository $settingsRepo,
        LoggerInterface $logger,
        Entity\Repository\StationRequestRepository $requestRepo,
        Adapters $adapters,
        EventDispatcher $dispatcher
    ) {
        parent::__construct($em, $settingsRepo, $logger);

        $this->requestRepo = $requestRepo;
        $this->dispatcher = $dispatcher;
        $this->adapters = $adapters;
    }

    /**
     * Manually process any requests for stations that use "Manual AutoDJ" mode.
     *
     * @param bool $force
     */
    public function run(bool $force = false): void
    {
        /** @var Entity\Repository\StationRepository $stations */
        $stations = $this->em->getRepository(Entity\Station::class)->findAll();

        foreach ($stations as $station) {
            /** @var Entity\Station $station */
            if (!$station->useManualAutoDJ()) {
                continue;
            }

            $request = $this->requestRepo->getNextPlayableRequest($station);
            if (null === $request) {
                continue;
            }

            $this->submitRequest($station, $request);
        }
    }

    protected function submitRequest(Entity\Station $station, Entity\StationRequest $request): bool
    {
        // Send request to the station to play the request.
        $backend = $this->adapters->getBackendAdapter($station);

        if (!($backend instanceof Liquidsoap)) {
            return false;
        }

        // Check for an existing SongHistory record and skip if one exists.
        $sh = $this->em->getRepository(Entity\SongHistory::class)->findOneBy([
            'station' => $station,
            'request' => $request,
        ]);

        if (!$sh instanceof Entity\SongHistory) {
            // Log the item in SongHistory.
            $media = $request->getTrack();

            $sh = new Entity\SongHistory($media->getSong(), $station);
            $sh->setTimestampCued(time());
            $sh->setMedia($media);
            $sh->setRequest($request);
            $sh->sentToAutodj();

            $this->em->persist($sh);
            $this->em->flush();
        }

        // Generate full Liquidsoap annotations
        $event = new AnnotateNextSong($station, $sh);
        $this->dispatcher->dispatch($event);

        $track = $event->buildAnnotations();

        // Queue request with Liquidsoap.
        if (!$backend->isQueueEmpty($station)) {
            $this->logger->error('Skipping submitting request to Liquidsoap; current queue is occupied.');
            return false;
        }

        $this->logger->debug('Submitting request to AutoDJ.', ['track' => $track]);

        $response = $backend->enqueue($station, $track);
        $this->logger->debug('AutoDJ request response', ['response' => $response]);

        // Log the request as played.
        $request->setPlayedAt(time());

        $this->em->persist($request);
        $this->em->flush();

        return true;
    }
}
