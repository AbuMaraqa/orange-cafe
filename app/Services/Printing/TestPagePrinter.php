<?php

namespace App\Services\Printing;

use App\Models\Printer;
use Mike42\Escpos\PrintConnectors\DummyPrintConnector;
use Mike42\Escpos\Printer as EscposPrinter;

/**
 * "اختبار الطباعة" — puts a real page through a printer.
 *
 * The difference from PrinterTransport::probe() matters and is the reason
 * both exist: a probe opens the socket and closes it, which proves the
 * printer is on the network and nothing else. A printer that is out of
 * paper, jammed, or has a stuck Windows spooler passes a probe and produces
 * nothing — so the person who has just plugged in a new printer and wants
 * to know whether it works needs the page, not the handshake.
 *
 * Plain ESC/POS text rather than the image renderer the tickets use: this
 * has to work when the image path is exactly what is broken, and Latin
 * digits and a couple of Arabic words are enough to tell whether paper
 * moves and the encoding is sane.
 */
class TestPagePrinter
{
    private string $lastError = '';

    public function __construct(private PrinterTransport $transport) {}

    public function print(Printer $printer): bool
    {
        $sent = $this->transport->send($printer, $this->page($printer));

        $this->lastError = $sent ? '' : ($this->transport->lastError() ?: __('printing.unreachable'));

        return $sent;
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    private function page(Printer $printer): string
    {
        $connector = new DummyPrintConnector;
        $escpos = new EscposPrinter($connector);

        $escpos->initialize();
        $escpos->setJustification(EscposPrinter::JUSTIFY_CENTER);
        $escpos->setEmphasis(true);
        $escpos->text("TEST PAGE\n");
        $escpos->setEmphasis(false);
        $escpos->text("صفحة اختبار\n\n");

        $escpos->setJustification(EscposPrinter::JUSTIFY_LEFT);
        $escpos->text("Printer : {$printer->name}\n");
        $escpos->text("Station : {$printer->station}\n");
        $escpos->text("Link    : {$printer->connection_type}\n");
        $escpos->text('Time    : '.now()->format('Y-m-d H:i:s')."\n\n");

        // A row of digits and a rule: if the paper is fed but the head is
        // failing, this is where it shows.
        $escpos->text("0123456789\n");
        $escpos->text(str_repeat('-', 32)."\n");

        $escpos->feed(2);
        $escpos->cut();

        $bytes = $connector->getData();
        $escpos->close();

        return $bytes;
    }
}
