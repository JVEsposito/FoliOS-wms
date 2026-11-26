<?php

return [
    'tesseract' => env('SAG_TESSERACT_BINARY', 'tesseract'),
    // Convierte la página del presample en PDF a imagen para Tesseract (paquete poppler-utils).
    'pdftoppm' => env('SAG_PDFTOPPM_BINARY', 'pdftoppm'),
];
