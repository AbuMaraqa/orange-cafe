<?php

return array (
  'unreachable' => 'The printer could not be reached.',
  'no_printer' => 'No printer',
  'no_printer_for_station' => 'No active printer is configured for the :station station.',
  'held_after_recovery' => 'The printer came back after this order was taken — it was not printed automatically. Reprint it by hand if it is still wanted.',
  'test_page' => 'Test page',
  'test_succeeded' => 'Printed successfully on :printer.',
  'test_failed' => 'Could not reach :printer — :error',
  'reprinted' => 'Sent to the printer again.',
  'already_printed' => 'This job has already been printed.',
  'no_printer_to_reprint' => 'This job has no printer attached to it.',
  'status' => 
  array (
    'online' => 'Working',
    'checking' => 'Checking',
    'offline' => 'Offline',
    'unresponsive' => 'Not responding',
    'unknown' => 'Unknown',
  ),
  'job_status' => 
  array (
    'pending' => 'Pending',
    'printing' => 'Printing',
    'printed' => 'Printed',
    'failed' => 'Failed',
    'printer_offline' => 'Printer offline',
  ),
  'cancelled' => 'Print job cancelled',
  'cancelled_many' => 'Cancelled :count print jobs',
);
