<?php
namespace App\Console\Command\Internal;

use App\Console\Command\CommandAbstract;
use App\Entity;
use App\Sync\Task\NowPlaying;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Console\Style\SymfonyStyle;

class FeedbackCommand extends CommandAbstract
{
    public function __invoke(
        SymfonyStyle $io,
        EntityManagerInterface $em,
        NowPlaying $nowPlaying,
        int $stationId,
        string $song = null,
        string $media = null,
        string $playlist = null
    ) {
        $station = $em->find(Entity\Station::class, $stationId);

        if (!($station instanceof Entity\Station)) {
            $io->write('false');
            return null;
        }

        try {
            $nowPlaying->queueStation($station, [
                'song_id' => $song,
                'media_id' => $media,
                'playlist_id' => $playlist,
            ]);

            $io->write('OK');
            return null;
        } catch (Exception $e) {
            $io->write('Error: ' . $e->getMessage());
            return 1;
        }
    }
}
