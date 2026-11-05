<?php

namespace App\Console\Commands;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Shared\AuditLogger;
use App\Enums\LocationType;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Local-only owner account setup (prompt §5, D-022). Never seeds a known
 * password; refuses to run in production.
 */
class CreateOwnerCommand extends Command
{
    protected $signature = 'brevon:create-owner
        {--name= : Full name}
        {--email= : Email address (defaults to BREVON_DEV_OWNER_EMAIL)}
        {--username= : Optional username}';

    protected $description = 'Create the local development Owner account (not for production)';

    public function handle(AuditLogger $audit): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to run in production. Create production accounts through the user administration screen.');

            return self::FAILURE;
        }

        $main = Branch::query()->where('location_type', LocationType::Permanent)->first();

        if ($main === null || ! Role::query()->where('name', PermissionCatalog::OWNER)->exists()) {
            $this->error('Run `php artisan db:seed` first (roles and the permanent location are required).');

            return self::FAILURE;
        }

        $email = (string) ($this->option('email') ?: config('brevon.dev_owner.email') ?: ($this->input->isInteractive() ? text('Email', required: true) : ''));
        $name = (string) ($this->option('name') ?: ($this->input->isInteractive() ? text('Full name', default: 'Brevon Owner', required: true) : 'Brevon Owner'));
        $username = $this->option('username') ? strtolower((string) $this->option('username')) : null;
        $plain = (string) (config('brevon.dev_owner.password') ?: ($this->input->isInteractive() ? password('Password (min 12 characters)', required: true) : ''));

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'username' => $username, 'password' => $plain],
            [
                'name' => ['required', 'string', 'max:150'],
                'email' => ['required', 'email', 'max:150', 'unique:users,email'],
                'username' => ['nullable', 'regex:/^[a-z0-9][a-z0-9._-]*$/', 'max:80', 'unique:users,username'],
                'password' => ['required', Password::min(12)->letters()->numbers()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($name, $email, $username, $plain, $main, $audit) {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'username' => $username,
                'password' => $plain,
                'is_active' => true,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole(PermissionCatalog::OWNER);
            $user->branches()->attach($main->id, ['is_default' => true]);

            $audit->record('user.created', $user, null, [
                'email' => $email,
                'roles' => [PermissionCatalog::OWNER],
                'locations' => [$main->code],
                'via' => 'brevon:create-owner',
            ]);

            return $user;
        });

        $this->info("Owner account created for {$user->email} at {$main->code}.");

        return self::SUCCESS;
    }
}
