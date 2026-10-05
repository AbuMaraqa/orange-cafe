<?php

namespace App\Services;

use App\Models\OrderItem;

/**
 * Which lines on a ticket this phone put there, and how many of each.
 *
 * A guest may change their own order until a waiter confirms it, and only
 * their own: five phones at one table share one ticket, and a guest tidying
 * up what they asked for must not be able to take a friend's coffee off it.
 * Nobody at the QR menu is logged in, so "their own" is remembered in the
 * session — the browser that placed the items is the one allowed to change
 * them. The order's URL is not enough on its own: it carries a sequential
 * id, and anybody can type the next number.
 *
 * A quantity rather than a yes/no, because lines merge (see
 * OrderItemWriter::matchingLine): two guests each ordering a juice is one
 * line of two, and each of them owns one of it, not the whole line.
 */
class GuestOrderLines
{
    private const KEY = 'guest_order_lines';

    /**
     * @param  array<int, int>  $added  [orderItemId => quantity], as
     *                                  OrderItemWriter::addLines() fills it
     */
    public function record(array $added): void
    {
        $lines = $this->all();

        foreach ($added as $itemId => $quantity) {
            $lines[$itemId] = ($lines[$itemId] ?? 0) + $quantity;
        }

        session()->put(self::KEY, $lines);
    }

    /**
     * How much of this line is this guest's.
     *
     * Never more than the line itself holds: a member of staff may have
     * lowered it since, and a guest cannot own plates that are no longer
     * on the ticket.
     */
    public function mine(OrderItem $item): int
    {
        return min($this->all()[$item->id] ?? 0, $item->quantity);
    }

    public function set(OrderItem $item, int $quantity): void
    {
        $lines = $this->all();

        if ($quantity > 0) {
            $lines[$item->id] = $quantity;
        } else {
            unset($lines[$item->id]);
        }

        session()->put(self::KEY, $lines);
    }

    /**
     * @return array<int, int>
     */
    private function all(): array
    {
        return session()->get(self::KEY, []);
    }
}
