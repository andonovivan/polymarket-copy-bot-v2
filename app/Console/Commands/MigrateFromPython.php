<?php

namespace App\Console\Commands;

use App\Models\BotMeta;
use App\Models\PnlSummary;
use App\Models\Position;
use App\Models\SeenTrade;
use App\Models\TrackedWallet;
use App\Models\TradeHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class MigrateFromPython extends Command
{
    protected $signature = 'bot:migrate-from-python {path? : Path to bot_state.db}';

    protected $description = 'One-time migration: import data from the Python bot\'s SQLite database into MySQL.';

    public function handle(): int
    {
        $path = $this->argument('path')
            ?? base_path('../polymarket-copy-bot/bot_state.db');

        if (! file_exists($path)) {
            $this->components->error("SQLite database not found at: {$path}");

            return self::FAILURE;
        }

        $this->components->info("Importing from: {$path}");

        $sqlite = new \PDO("sqlite:{$path}");
        $sqlite->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // --- Positions ---
        $rows = $sqlite->query('SELECT asset_id, shares, exposure, buy_price, opened_at FROM positions WHERE shares > 0 OR exposure > 0');
        $posCount = 0;
        foreach ($rows as $row) {
            Position::updateOrCreate(
                ['asset_id' => $row['asset_id']],
                [
                    'shares' => (float) $row['shares'],
                    'exposure' => (float) $row['exposure'],
                    'buy_price' => (float) $row['buy_price'],
                    'opened_at' => $row['opened_at'] ? Carbon::createFromTimestamp((int) $row['opened_at']) : null,
                ],
            );
            $posCount++;
        }
        $this->components->info("Imported {$posCount} positions.");

        // --- Tracked wallets ---
        $rows = $sqlite->query('SELECT wallet FROM tracked_wallets');
        $walletCount = 0;
        foreach ($rows as $row) {
            TrackedWallet::firstOrCreate(['address' => strtolower($row['wallet'])]);
            $walletCount++;
        }
        $this->components->info("Imported {$walletCount} tracked wallets.");

        // --- PnL Summary ---
        $row = $sqlite->query('SELECT total_realized, total_trades, winning_trades, losing_trades FROM pnl_summary WHERE id = 1')->fetch();
        if ($row) {
            $summary = PnlSummary::singleton();
            $summary->update([
                'total_realized' => (float) $row['total_realized'],
                'total_trades' => (int) $row['total_trades'],
                'winning_trades' => (int) $row['winning_trades'],
                'losing_trades' => (int) $row['losing_trades'],
            ]);
            $this->components->info('Imported PnL summary.');
        }

        // --- Trade history ---
        $rows = $sqlite->query('SELECT asset_id, buy_price, sell_price, shares, pnl, ts, opened_at, closed_at FROM trade_history ORDER BY id');
        $tradeCount = 0;
        foreach ($rows as $row) {
            TradeHistory::create([
                'asset_id' => $row['asset_id'],
                'buy_price' => (float) $row['buy_price'],
                'sell_price' => (float) $row['sell_price'],
                'shares' => (float) $row['shares'],
                'pnl' => (float) $row['pnl'],
                'opened_at' => $row['opened_at'] ? Carbon::createFromTimestamp((int) $row['opened_at']) : null,
                'closed_at' => $row['closed_at'] ? Carbon::createFromTimestamp((int) $row['closed_at']) : Carbon::createFromTimestamp((int) $row['ts']),
            ]);
            $tradeCount++;
        }
        $this->components->info("Imported {$tradeCount} trade history records.");

        // --- Meta (last_running_ts) ---
        $row = $sqlite->query("SELECT value FROM meta WHERE key = 'last_running_ts'")->fetch();
        if ($row) {
            BotMeta::setValue('last_running_ts', $row['value']);
            $this->components->info('Imported last_running_ts.');
        }

        $this->components->info('Migration complete!');

        return self::SUCCESS;
    }
}
