<?php

namespace App\Console\Commands;

use App\Services\LeaderboardDiscovery;
use Illuminate\Console\Command;

class DiscoverWallets extends Command
{
    protected $signature = 'bot:discover-wallets';

    protected $description = 'Discover high-performing traders from the Polymarket leaderboard and auto-add them';

    public function handle(LeaderboardDiscovery $discovery): int
    {
        $candidates = $discovery->discoverCandidates();

        // Filter to only non-tracked candidates.
        $candidates = array_filter($candidates, fn ($c) => ! $c['already_tracked']);

        if (empty($candidates)) {
            $this->components->info('No new candidates found.');

            return self::SUCCESS;
        }

        $maxAdd = (int) config('polymarket.discover_max_auto_add', 3);
        $added = 0;

        foreach (array_slice($candidates, 0, $maxAdd) as $candidate) {
            if ($discovery->addCandidate($candidate)) {
                $added++;
                $this->components->info("Added: {$candidate['username']} ({$candidate['wallet']}) — PNL: \${$candidate['pnl']}");
            }
        }

        $this->components->info("Discovery complete: {$added} wallet(s) added from " . count($candidates) . ' candidates.');

        return self::SUCCESS;
    }
}
