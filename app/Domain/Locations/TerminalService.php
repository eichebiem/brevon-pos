<?php

namespace App\Domain\Locations;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\BusinessClock;
use App\Models\Branch;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POS terminals (FR-106, D-060, N-12). Each terminal belongs to one
 * location; moving it is an audited reassignment.
 */
class TerminalService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(User $actor, Branch $location, string $code, string $name): Terminal
    {
        $code = strtoupper(trim($code));
        $this->assertCodeFree($location, $code);

        return DB::transaction(function () use ($actor, $location, $code, $name) {
            $terminal = Terminal::query()->create([
                'branch_id' => $location->id,
                'terminal_code' => $code,
                'name' => $name,
                'is_active' => true,
            ]);

            $this->audit->record('terminal.created', $terminal, null, ['code' => $code, 'name' => $name, 'location' => $location->code], $location->id, $actor);

            return $terminal;
        });
    }

    public function update(User $actor, Terminal $terminal, string $name, bool $active): Terminal
    {
        if (! $active && $terminal->is_active && $terminal->hasOpenShift()) {
            throw ValidationException::withMessages(['is_active' => __('Close the open shift on :code before deactivating it.', ['code' => $terminal->terminal_code])]);
        }

        $old = ['name' => $terminal->name, 'is_active' => $terminal->is_active];
        $terminal->fill(['name' => $name, 'is_active' => $active]);

        if (! $terminal->isDirty()) {
            return $terminal;
        }

        $terminal->save();
        $new = ['name' => $terminal->name, 'is_active' => $terminal->is_active];
        $this->audit->record('terminal.updated', $terminal, array_diff_assoc($old, $new), array_diff_assoc($new, $old), $terminal->branch_id, $actor);

        return $terminal;
    }

    /**
     * Audited reassignment to another operating location (FR-106). The
     * destination must be able to operate today (D-059).
     */
    public function reassign(User $actor, Terminal $terminal, Branch $destination): Terminal
    {
        if ($terminal->branch_id === $destination->id) {
            throw ValidationException::withMessages(['branch_id' => __('The terminal is already at this location.')]);
        }

        if (! $destination->acceptsNewOperationsOn(BusinessClock::today())) {
            throw ValidationException::withMessages(['branch_id' => __('Terminals can only be moved to a location that is active and within its operating dates.')]);
        }

        $this->assertCodeFree($destination, $terminal->terminal_code, 'branch_id');

        // D-060: a terminal's sales must stay with the location its open shift started at.
        if ($terminal->hasOpenShift()) {
            throw ValidationException::withMessages(['branch_id' => __('Close the open shift on :code before moving it.', ['code' => $terminal->terminal_code])]);
        }

        return DB::transaction(function () use ($actor, $terminal, $destination) {
            /** @var Branch $from */
            $from = $terminal->branch;
            $terminal->forceFill(['branch_id' => $destination->id])->save();

            $this->audit->record('terminal.reassigned', $terminal, ['location' => $from->code], ['location' => $destination->code], $destination->id, $actor);

            return $terminal;
        });
    }

    private function assertCodeFree(Branch $location, string $code, string $field = 'terminal_code'): void
    {
        if ($location->terminals()->where('terminal_code', $code)->exists()) {
            throw ValidationException::withMessages([$field => __('A terminal with code :code already exists at :location.', ['code' => $code, 'location' => $location->code])]);
        }
    }
}
