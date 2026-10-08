<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Support;

class TenantResolver
{
    public function id(): string
    {
        // 1) із заголовка — лише якщо його явно ввімкнено: заголовок шле клієнт, і довіра
        //    за замовчуванням дала б будь-кому списати витрати на чужий tenant
        $header = config('ai-tasks.tenant_header');

        if ($header && ($id = request()->header($header))) {
            return (string) $id;
        }

        // 2) з авторизованого користувача
        if ($u = auth()->user()) {
            // підлаштуй назву поля під свій проєкт
            return (string) ($u->tenant_id ?? $u->company_id ?? $u->id ?? 'default');
        }

        // 3) із конфіга
        return (string) config('ai-tasks.default_tenant', 'default');
    }
}
