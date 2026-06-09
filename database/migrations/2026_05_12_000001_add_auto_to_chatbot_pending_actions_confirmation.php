<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.1.3 (#16) — extends the `confirmation` enum column to accept `auto`.
 *
 * Before v1.1.3, frontend tools with `confirmation=Auto` did not persist a
 * row in `chatbot_pending_actions` (they were fire-and-forget). v1.1.3
 * introduces a "confirmed-at-birth, may transition to executed on widget
 * failure" lifecycle for Auto actions so the LLM can recover from silent
 * primitive failures (`fill_form` couldn't find the form, `navigate` got a
 * cross-origin URL, etc.). That requires the column to accept the new value.
 *
 * For SQLite (typical test/dev driver) ENUMs are stored as TEXT and accept
 * any value — the migration is effectively a no-op there. For MySQL/Postgres
 * we issue the appropriate ALTER. New installations don't need this: the
 * base migration (2026_05_09_000001) already ships the wider `string(16)`
 * column directly, so on a fresh install this migration is a no-op too
 * (the existing column already accepts `auto`).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = $this->driver();
        $table = $this->table();

        match ($driver) {
            'mysql', 'mariadb' => DB::connection($this->getConnection())->statement(
                'ALTER TABLE ' . $this->quoteIdentifier($table, $driver)
                . " MODIFY confirmation ENUM('confirm', 'manual', 'auto') NOT NULL"
            ),
            'pgsql' => DB::connection($this->getConnection())->statement(
                // Postgres stores Laravel `enum()` as a CHECK constraint or a
                // dedicated type depending on the version. Re-issuing the
                // check is the safest cross-version path: drop and recreate.
                'ALTER TABLE ' . $this->quoteIdentifier($table, $driver)
                . ' DROP CONSTRAINT IF EXISTS ' . $this->quoteIdentifier($table . '_confirmation_check', $driver) . ', '
                . 'ADD CONSTRAINT ' . $this->quoteIdentifier($table . '_confirmation_check', $driver) . ' '
                . "CHECK (confirmation IN ('confirm', 'manual', 'auto'))"
            ),
            // sqlite / sqlsrv / others: no-op (ENUM not enforced or schema
            // requires a full table rebuild that isn't worth automating).
            default => null,
        };
    }

    public function down(): void
    {
        $driver = $this->driver();
        $table = $this->table();

        // Down: revert to 1.1.2's 2-value enum. Pre-existing `auto` rows are
        // left alone — they'll fail subsequent updates but won't crash here.
        match ($driver) {
            'mysql', 'mariadb' => DB::connection($this->getConnection())->statement(
                'ALTER TABLE ' . $this->quoteIdentifier($table, $driver)
                . " MODIFY confirmation ENUM('confirm', 'manual') NOT NULL"
            ),
            'pgsql' => DB::connection($this->getConnection())->statement(
                'ALTER TABLE ' . $this->quoteIdentifier($table, $driver)
                . ' DROP CONSTRAINT IF EXISTS ' . $this->quoteIdentifier($table . '_confirmation_check', $driver) . ', '
                . 'ADD CONSTRAINT ' . $this->quoteIdentifier($table . '_confirmation_check', $driver) . ' '
                . "CHECK (confirmation IN ('confirm', 'manual'))"
            ),
            default => null,
        };
    }

    public function getConnection(): ?string
    {
        return config('chatbot.persistence.connection');
    }

    protected function table(): string
    {
        return config('chatbot.persistence.prefix', 'chatbot_') . 'pending_actions';
    }

    protected function driver(): string
    {
        return Schema::connection($this->getConnection())
            ->getConnection()
            ->getDriverName();
    }

    /**
     * Quote identifiers per-driver. MySQL/MariaDB use backticks, Postgres uses
     * double quotes. The defensive strip-and-wrap protects against unusual
     * prefixes that might already contain the quote character.
     */
    protected function quoteIdentifier(string $name, string $driver): string
    {
        return match ($driver) {
            'mysql', 'mariadb' => '`' . str_replace('`', '', $name) . '`',
            'pgsql' => '"' . str_replace('"', '', $name) . '"',
            default => $name,
        };
    }
};
