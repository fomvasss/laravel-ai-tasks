<?php

namespace Fomvasss\AiTasks\Support;

use Illuminate\Support\Arr;

class TenantResolver
{
    public function id(): string
    {
        // 1) з запиту (X-Tenant-Id)
        if ($id = request()->header('X-Tenant-Id')) {
            return (string) $id;
        }

        // 2) з авторизованого користувача
        if ($u = auth()->user()) {
            // підлаштуй назву поля під свій проєкт
            return (string) ($u->tenant_id ?? $u->company_id ?? $u->id ?? 'default');
        }

        // 3) із конфіга
        return (string) config('ai.default_tenant', 'default');
    }
}
