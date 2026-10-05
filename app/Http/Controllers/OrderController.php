<?php

namespace App\Http\Controllers;

use App\Events\OrderPlaced;
use App\Http\Controllers\Concerns\BroadcastsSafely;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Services\GuestOrderLines;
use App\Services\KitchenPrinterService;
use App\Services\OrderItemWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    use BroadcastsSafely;

    // No cashier printer here: placing an order prints to kitchens only,
    // and the bill is a separate action at the till.
    public function __construct(
        protected OrderItemWriter $itemWriter,
        protected KitchenPrinterService $printer,
        protected GuestOrderLines $guestLines,
    ) {}

    public function store(StoreOrderRequest $request): RedirectResponse
    {
        $data = $request->validated();
        // A member of staff typing an order in themselves has already
        // effectively reviewed it — it goes straight to the kitchen. A
        // customer's own order (from the QR menu) stays "pending" and
        // unprinted until a waiter reviews and confirms it (see
        // StaffOrderController::confirm()).
        //
        // The list includes the cashier, who can open a table from the till.
        // It has to: a role that reaches this line without being on it has
        // its order recorded as an unconfirmed customer order, so nothing
        // prints and the kitchen never hears about a table the cashier is
        // standing in front of.
        $placedByStaff = $request->user()?->hasAnyRole(config('orders.placed_by_roles')) ?? false;

        // Five people sitting at one table each scan the same QR and order
        // from their own phone. Their items belong on that table's one open
        // ticket: five separate orders would reach the waiter as five things
        // to confirm, print as five kitchen tickets for one table, and bill
        // as five totals that somebody has to add up by hand.
        //
        // Later rounds join it too — the guests who ordered mains and then
        // want dessert are the same bill. The kitchen still gets the round on
        // its own slip below, so nothing is silently edited into a ticket
        // that has already been cooked; only the billing is unified.
        if ($joinable = $this->joinableTableOrder($data, $placedByStaff)) {
            // Which lines this round actually added, per line — see
            // addLines(). Read after the transaction commits, because a print
            // job queued inside one that then rolls back is paper for food
            // nobody ordered.
            $added = [];

            // Decided before the write, because addLines() may move the
            // ticket forward and the question is what the kitchen knew when
            // the guest pressed the button.
            $awaitingReview = $joinable->status === 'pending';

            DB::transaction(function () use ($joinable, $data, &$added) {
                $addedCents = $this->itemWriter->addLines($joinable, $data['items'], $added);
                $joinable->increment('total_cents', $addedCents);

                // The pass had called this table done. More food means more
                // to prepare, so it rejoins the queue rather than sitting on
                // a "ready" shelf nobody is watching any more.
                if ($joinable->status === 'ready') {
                    $joinable->update(['status' => 'confirmed']);
                }

                // Details the running ticket never captured. Only ever
                // filled in, never overwritten: the guest count taken when
                // the table sat down is better than one typed by somebody
                // adding a round of coffees an hour later.
                $joinable->fill(array_filter([
                    'guest_count' => $joinable->guest_count === null ? ($data['guest_count'] ?? null) : null,
                    'customer_name' => $joinable->customer_name === null ? ($data['customer_name'] ?? null) : null,
                ]))->save();
            });

            $joinable->refresh();

            // Remembered so this phone can change its own share of the
            // ticket until a waiter confirms it — see GuestOrderLines.
            if (! $placedByStaff) {
                $this->guestLines->record($added);
            }

            // Same event as a brand-new order on purpose: the waiter screen
            // upserts on it, so the table's card updates in place with the
            // added items and alerts whoever is on the floor. An addition
            // nobody notices is the same as an order nobody took.
            $this->safeBroadcast(new OrderPlaced($joinable, isAddition: true));

            // The round goes to the pass on its own slip — scoped to the
            // added lines so it carries its own idempotency key, exactly as
            // WaiterOrderController::addItems does. Asking for the whole
            // ticket here would match the key of the ticket that already
            // printed, and nothing would be queued at all.
            //
            // The exception is a customer adding to a ticket no waiter has
            // reviewed yet: that ticket prints in one piece at confirm(), so
            // queuing now would send the kitchen the same round twice. Staff
            // have already done the reviewing, so theirs goes immediately.
            if ($placedByStaff || ! $awaitingReview) {
                $this->printer->queuePrintJobs($joinable, onlyItemIds: $added);
            }

            // Staff back to their own floor plan on the table they were
            // working, customers to their order status page — the same split
            // a brand-new order makes below.
            return $placedByStaff
                ? to_route(
                    $request->user()->homeRouteName(),
                    $joinable->table_id ? ['table' => $joinable->table_id] : [],
                )->with('success', __('menu.order_placed'))
                : to_route('menu.orders.show', $joinable)->with('success', __('menu.order_placed'));
        }

        $added = [];

        $order = DB::transaction(function () use ($data, $request, $placedByStaff, &$added) {
            $order = Order::create([
                'table_id' => $data['table_id'] ?? null,
                'table_number' => $data['table_number'] ?? null,
                'guest_count' => $data['guest_count'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => $placedByStaff ? 'confirmed' : config('orders.default_status'),
                'locale' => app()->getLocale(),
                'created_by' => $request->user()?->id,
                'total_cents' => 0,
            ]);

            $total = $this->itemWriter->addLines($order, $data['items'], $added);
            $order->update(['total_cents' => $total]);

            return $order;
        });

        $this->safeBroadcast(new OrderPlaced($order));

        if (! $placedByStaff) {
            $this->guestLines->record($added);
        }

        if ($placedByStaff) {
            // Kitchens only, and the same rule as
            // StaffOrderController::confirm(): placing an order tells the
            // people who have to cook it, and nothing else.
            //
            // The bill is not part of accepting an order. A table that has
            // just ordered will order again and will not pay for an hour, so
            // a receipt printed now is paper nobody asked for showing a
            // total that is about to change. It prints when somebody presses
            // "طباعة للكاشير" — see StaffOrderController::printForCashier.
            //
            // Both acceptance paths have to agree on this. A waiter typing
            // an order in never passes through confirm() — it is born
            // confirmed — so a rule applied only there would leave the most
            // common case in the cafe behaving the opposite way.
            $this->printer->queuePrintJobs($order);

            // Staff placing an order on a customer's behalf want to get
            // straight back to work, not land on the customer-facing
            // order-status page.
            //
            // Back to *their own* screen, not the waiter's: the cashier
            // cannot open /waiter, so sending them there after a successful
            // order would answer the sale with a 403.
            //
            // Carrying the table means the floor plan reopens on the table
            // just ordered for, rather than dropping the waiter at the top
            // of the room to find it again — which on a busy floor is the
            // difference between one tap and hunting through thirty tables.
            return to_route(
                $request->user()->homeRouteName(),
                $order->table_id ? ['table' => $order->table_id] : [],
            )->with('success', __('menu.order_placed'));
        }

        return to_route('menu.orders.show', $order)->with('success', __('menu.order_placed'));
    }

    /**
     * The table's running ticket that this customer's items should join, or
     * null when a new one has to be created.
     *
     * Any ticket the table has not finished with counts, not just one the
     * waiter has yet to confirm. Restricting this to 'pending' is what put
     * a second order on a table that already had one, and the floor plan
     * shows a table's *latest* order — so the first one, its items and its
     * money silently disappeared from the bill while the kitchen cooked and
     * served them. A table pays one bill; a table therefore has one ticket.
     *
     * Paid tickets are excluded on purpose. Once a bill is settled the next
     * order is the next group of guests, and folding their drinks into a
     * closed ticket would reopen a till transaction that already balanced.
     *
     * Who may join what differs, and the difference is review.
     *
     * Staff have already reviewed the order — they are standing at the table
     * taking it — so theirs joins whatever the table is running. That also
     * closes a trap in the waiter screen: it only offers "add to the running
     * ticket" when the table was opened from the floor plan, and picking the
     * same table from the dropdown posts here instead. Deciding from the
     * table rather than from how the screen was reached means a waiter
     * cannot split a bill by arriving a different way.
     *
     * A customer has been reviewed by nobody. Their items may join a ticket
     * that is *also* still awaiting review, which is what lets five guests
     * order from five phones and reach the waiter as one thing to confirm.
     * Once that ticket is confirmed the gate closes behind it: the next
     * round becomes its own pending order, alerts the floor exactly as the
     * first one did, and StaffOrderController::confirm() folds it into the
     * table's bill at the moment a member of staff accepts it.
     *
     * Letting a customer join a confirmed ticket — which this did briefly —
     * put items on the bill and paper in the kitchen with no member of staff
     * in between. One bill per table is the rule; it is not a reason to let
     * the customer past the door unaccompanied.
     *
     * @param  array<string, mixed>  $data
     */
    protected function joinableTableOrder(array $data, bool $placedByStaff): ?Order
    {
        if (empty($data['table_id'])) {
            return null;
        }

        return Order::query()
            ->where('table_id', $data['table_id'])
            ->whereNull('paid_at')
            ->when(
                $placedByStaff,
                fn ($q) => $q->whereIn('status', config('orders.active_statuses')),
                fn ($q) => $q->where('status', 'pending'),
            )
            ->latest()
            ->first();
    }

    public function show(Order $order): Response
    {
        $order->load('items.modifiers', 'table');

        return Inertia::render('Customer/OrderStatus', [
            'order' => $this->present($order),
        ]);
    }

    protected function present(Order $order): array
    {
        // Until a waiter confirms it, nothing has reached the kitchen and the
        // guest may still change their own lines. After that the paper is in
        // the kitchen and any change is the waiter's.
        $editable = $order->status === 'pending' && ! $order->isPaid();

        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'status' => $order->status,
            'status_color' => $order->statusColor(),
            'table_label' => $order->table?->label ?? $order->table_number,
            // Carried so "back to the menu" returns to *this* table's menu.
            // Without it the link dropped the table and the guest's next
            // round arrived as an order belonging to nobody — the QR code is
            // on the table, and after ordering once nobody scans it again.
            'table_id' => $order->table_id,
            'total_cents' => $order->total_cents,
            'is_paid' => $order->isPaid(),
            'editable' => $editable,
            'created_at' => $order->created_at->toIso8601String(),
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                // How much of this line the phone looking at it added, and
                // so may change. Zero for everybody else's — see
                // GuestOrderLines.
                'mine' => $editable ? $this->guestLines->mine($item) : 0,
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'unit_price_cents' => $item->unit_price_cents,
                'subtotal_cents' => $item->subtotal_cents,
                'notes' => $item->notes,
                'modifiers' => $item->modifiers->map(fn ($modifier) => [
                    'name' => $modifier->modifier_name,
                    'price_cents' => $modifier->price_cents,
                ]),
            ]),
        ];
    }
}
