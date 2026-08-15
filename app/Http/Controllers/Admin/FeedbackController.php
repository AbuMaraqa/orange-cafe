<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FeedbackController extends Controller
{
    public function index(): Response
    {
        $feedback = Feedback::query()->with('restaurantTable')->latest()->get();

        return Inertia::render('Admin/Feedback/Index', [
            'feedback' => $feedback->map(fn (Feedback $item) => [
                'id' => $item->id,
                'message' => $item->message,
                'table_label' => $item->tableLabel(),
                'is_read' => $item->is_read,
                'created_at' => $item->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function markRead(Feedback $feedback): RedirectResponse
    {
        $feedback->update(['is_read' => true]);

        return back();
    }

    public function destroy(Feedback $feedback): RedirectResponse
    {
        $feedback->delete();

        return back()->with('success', __('admin.feedback.deleted'));
    }
}
