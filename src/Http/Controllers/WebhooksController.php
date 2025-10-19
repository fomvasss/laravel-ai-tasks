<?php

namespace Fomvasss\AiTasks\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WebhooksController extends Controller
{
    public function openai(Request $r)
    {
        // валідатор підпису і оновлення AiRun за run_id — реалізуй під конкретний флоу
        return response()->json(['ok'=>true]);
    }

    public function gemini(Request $r)
    {
        return response()->json(['ok'=>true]);
    }
}
