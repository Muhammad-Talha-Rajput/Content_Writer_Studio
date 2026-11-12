"""Read an untrusted text PDF in a bounded worker; never render or execute it."""
import json
import sys
import warnings

from pypdf import PdfReader

warnings.filterwarnings("ignore")


def extract(path):
    reader = PdfReader(path, strict=True)
    if reader.is_encrypted:
        raise ValueError("Password-protected PDFs are not supported.")
    if not 1 <= len(reader.pages) <= 100:
        raise ValueError("Use a PDF with between 1 and 100 pages.")
    # Resolve every object, including compressed object streams, before extraction.
    forbidden = {"/JS", "/JavaScript", "/Launch", "/EmbeddedFiles", "/EmbeddedFile", "/RichMedia", "/OpenAction", "/AA", "/XFA", "/GoToR", "/SubmitForm", "/ImportData"}
    seen = set()

    def inspect(obj, depth=0):
        if depth > 60 or len(seen) > 100000:
            raise ValueError("The PDF structure exceeds the supported complexity.")
        if hasattr(obj, "get_object"):
            obj = obj.get_object()
        if id(obj) in seen:
            return
        seen.add(id(obj))
        if isinstance(obj, dict):
            if forbidden.intersection(str(key) for key in obj):
                raise ValueError("PDFs with scripts, actions, forms, or embedded files are not supported.")
            if str(obj.get("/S", "")) in forbidden or str(obj.get("/Type", "")) in forbidden:
                raise ValueError("The PDF contains active or embedded content.")
            for value in obj.values():
                inspect(value, depth + 1)
        elif isinstance(obj, (list, tuple)):
            for value in obj:
                inspect(value, depth + 1)

    inspect(reader.trailer)
    parts = []
    total = 0
    for number, page in enumerate(reader.pages, 1):
        stream = page.get_contents()
        if stream and len(stream.get_data()) > 5 * 1024 * 1024:
            raise ValueError("A PDF page is too complex to extract.")
        text = page.extract_text() or ""
        total += len(text)
        if total > 150000:
            raise ValueError("The PDF has too much text. Use a shorter document.")
        parts.append({"locator": f"Page {number}", "page": number, "text": text})
    if total < 10:
        raise ValueError("This PDF has no readable text. Upload a text PDF or a TXT transcription; OCR is not available.")
    return parts


try:
    print(json.dumps({"parts": extract(sys.argv[1])}, ensure_ascii=False))
except Exception as error:
    message = str(error) if isinstance(error, ValueError) else "The PDF is malformed or cannot be read."
    print(json.dumps({"error": message}))
    sys.exit(1)
