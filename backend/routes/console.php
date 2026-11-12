<?php

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\AccountRules;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('studio:create-admin {email} {--name=Grands Digital Administrator}', function () {
    $data = ['email' => mb_strtolower(trim($this->argument('email'))), 'name' => $this->option('name'),
        'password' => $this->secret('Password'), 'password_confirmation' => $this->secret('Confirm password')];
    $validator = Validator::make($data, [
        'email' => ['required', 'email:rfc', 'max:254', 'unique:users,email'],
        'name' => ['required', 'string', 'max:200'],
        'password' => AccountRules::password(),
    ]);
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }
    AccountRules::exclusive(fn () => DB::transaction(function () use ($data) {
        $user = User::create([...$data, 'role' => 'ADMIN', 'is_active' => true]);
        AuditEvent::record(null, 'administrator.provisioned', 'USER', $user->id);
    }));
    $this->info('Administrator created. Sign in through the web application.');

    return 0;
})->purpose('Provision an administrator using hidden password prompts');
