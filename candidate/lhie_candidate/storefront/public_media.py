"""Read only sanitized public images from the P01 disposable storage volume."""
import os
from pathlib import Path
import stat

from django.http import FileResponse, Http404
from django.views.decorators.http import require_safe
from PIL import Image, UnidentifiedImageError


PUBLIC_ROOT = Path('/disposable/public')
MAX_BYTES = 5 * 1024 * 1024
MAX_SIDE = 4096
CONTENT_TYPES = {'PNG': 'image/png', 'JPEG': 'image/jpeg', 'WEBP': 'image/webp', 'GIF': 'image/gif'}


def open_image(path):
    relative = Path(path)
    if relative.is_absolute() or not relative.parts or any(part in ('.', '..') for part in relative.parts):
        raise Http404
    try:
        root = PUBLIC_ROOT.resolve(strict=True)
        resolved = (root / relative).resolve(strict=True)
        if not resolved.is_relative_to(root):
            raise Http404
        descriptor = os.open(resolved, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        stream = os.fdopen(descriptor, 'rb')
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or not 0 < info.st_size <= MAX_BYTES:
            stream.close()
            raise Http404
        try:
            with Image.open(stream) as picture:
                content_type = CONTENT_TYPES.get(picture.format)
                size = picture.size
                if not content_type or not all(0 < side <= MAX_SIDE for side in size):
                    raise Http404
                picture.verify()
            stream.seek(0)
        except (UnidentifiedImageError, Image.DecompressionBombError, OSError, ValueError, SyntaxError):
            stream.close()
            raise Http404 from None
        except BaseException:
            stream.close()
            raise
        return stream, content_type, size
    except (OSError, ValueError):
        raise Http404 from None


@require_safe
def image(request, path):
    stream, content_type, _ = open_image(path)
    response = FileResponse(stream, content_type=content_type)
    response['X-Content-Type-Options'] = 'nosniff'
    return response
