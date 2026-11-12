<?php

return [
    'scanner' => env('SOURCE_SCANNER', 'defender'),
    'scanner_binary' => env('SOURCE_SCANNER_BINARY', 'C:\\Program Files\\Windows Defender\\MpCmdRun.exe'),
    'pdftotext' => env('SOURCE_PDFTOTEXT_BINARY', 'pdftotext'),
    'pdfinfo' => env('SOURCE_PDFINFO_BINARY', 'pdfinfo'),
    'connection' => env('SOURCE_QUEUE_CONNECTION', 'sources'),
    'python' => env('SOURCE_PYTHON_BINARY', 'python'),
    'max_text' => 150000,
];
