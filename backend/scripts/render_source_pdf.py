"""Render a single already-scanned private PDF page without a browser plugin."""
import json
import sys
import pypdfium2 as pdfium

try:
    document = pdfium.PdfDocument(sys.argv[1])
    count = len(document)
    number = int(sys.argv[2])
    if not 1 <= number <= min(count, 100):
        raise ValueError('Invalid page')
    page = document[number - 1]
    width, height = page.get_size()
    if min(width, height) <= 0:
        raise ValueError('Invalid page size')
    bitmap = page.render(scale=min(1.5, 1800 / max(width, height)), draw_annots=False)
    image = bitmap.to_pil()
    image.save(sys.argv[3], format='PNG')
    image.close()
    bitmap.close()
    page.close()
    document.close()
    print(json.dumps({'pages': count}))
except Exception:
    print(json.dumps({'error': 'The PDF page could not be displayed.'}))
    sys.exit(1)
