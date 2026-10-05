<?php

namespace App\Http\Middleware;

use App\Models\Order;
use App\Services\LanguageRegistry;
use App\Settings\RestaurantSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Middleware;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $languages = app(LanguageRegistry::class);
        $branding = app(RestaurantSettings::class);
        $current = app()->getLocale();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user()?->only(['id', 'name', 'username', 'locale']),
                'roles' => $request->user()?->getRoleNames() ?? [],
                // Shared so the sidebar can hide the money screens from
                // someone who would only be turned away at the door. The
                // routes are guarded independently — this is what the menu
                // shows, never what it permits.
                // A closure, not a value: share() runs on every request,
                // and a plain expression here costs two queries and a
                // merge even on a partial reload that asked only for
                // `orders` — which the socket reconnect now fires. Inertia
                // never calls a closure for a prop the response excluded.
                'permissions' => fn () => $request->user()?->getAllPermissions()->pluck('name') ?? [],
                // Which staff screen is "home" for this person, so a shared
                // page can send them back to their own floor plan. The order
                // screen is reached by both a waiter and a cashier, and
                // hardcoding /waiter there answers the cashier with a 403.
                'home_route' => fn () => $request->user()?->homeRouteName(),
                // One fact rather than a copy of the role list in JS. The
                // list lives in config/orders.php; deriving it here means a
                // screen asks "am I staff" instead of re-deciding what staff
                // means.
                'is_staff' => fn () => $request->user()?->hasAnyRole(config('orders.placed_by_roles')) ?? false,
                // Same idea for the one destructive button on a bill: the
                // screen asks this rather than knowing which roles count.
                'can_cancel_confirmed' => fn () => $request->user()?->hasAnyRole(config('orders.cancel_confirmed_roles')) ?? false,
            ],
            'locale' => [
                'current' => $current,
                'direction' => $languages->direction($current),
                'default' => $languages->default()?->code,
                'available' => $languages->active()->map(fn ($language) => [
                    'code' => $language->code,
                    'name' => $language->name,
                    'native_name' => $language->native_name,
                    'direction' => $language->direction,
                    'url' => LaravelLocalization::getLocalizedURL($language->code, null, [], true),
                ])->values(),
            ],
            'branding' => [
                'name' => $branding->name,
                'tagline' => $branding->tagline,
                'address' => $branding->address,
                'logo_url' => $branding->logo_path ? Storage::disk('public')->url($branding->logo_path) : null,
                'cover_image_url' => $branding->cover_image_path ? Storage::disk('public')->url($branding->cover_image_path) : null,
                'primary_color' => $branding->primary_color,
                'secondary_color' => $branding->secondary_color,
                'font_family' => $branding->font_family,
                'currency_symbol' => $branding->currency_symbol,
                'phone' => $branding->phone,
                'whatsapp' => $branding->whatsapp,
                'instagram' => $branding->instagram,
                'facebook' => $branding->facebook,
            ],
            // 'warning' and 'error' exist because printing can fail in ways
            // that are nobody's mistake and everybody's problem — a station
            // with no printer, a ticket that had nothing left to send. Those
            // were previously reported through the 'success' channel, which
            // is how a cashier ended up being told a bill had printed when
            // the app knew perfectly well that it had not.
            // Every order still waiting for a waiter to look at it, on every
            // staff screen, whatever that screen is about.
            //
            // A customer's QR order lands as 'pending' and stays there until
            // a waiter confirms it. The floor plan shows that fine — if you
            // are looking at the floor plan. A waiter mid-order at another
            // table, or on the till, or on the kitchen screen, has no idea:
            // the alert card that announced it has long since dismissed
            // itself, and nothing else on screen mentions it.
            //
            // Shared here rather than passed per-page because the whole
            // point is the screens that are *not* about orders. The socket
            // keeps it current after that (usePendingOrders) — this is only
            // the starting state for a fresh page load.
            'pending_orders' => fn () => $this->pendingOrders($request),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'warning' => fn () => $request->session()->get('warning'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * Orders a waiter has not acted on yet, newest first.
     *
     * Capped: past a couple of dozen the number has stopped being
     * actionable and become a symptom, and the badge says "20+" either way.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pendingOrders(Request $request): array
    {
        $user = $request->user();

        // Customers reach these same pages. They must never be told what
        // other tables have ordered, and the query is wasted work for them.
        // config/orders.php owns this list, and its header asks for
        // exactly this: "listed here rather than repeated across the
        // routes, OrderController and the Vue screens".
        if (! $user?->hasAnyRole(config('orders.placed_by_roles'))) {
            return [];
        }

        return Order::query()
            ->where('status', 'pending')
            ->with('table:id,label')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'reference' => $order->reference,
                'table_id' => $order->table_id,
                'table_label' => $order->table?->label,
                'created_at' => $order->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
