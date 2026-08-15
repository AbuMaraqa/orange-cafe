<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    /**
     * Customer-facing: raised from the menu page, never requires
     * authentication — same trust model as placing an order or calling a
     * waiter.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'table_id' => ['nullable', 'integer', 'exists:restaurant_tables,id'],
            'table_number' => ['nullable', 'string', 'max:50'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        Feedback::create($data);

        return back()->with('success', __('menu.feedback_sent'));
    }
}
