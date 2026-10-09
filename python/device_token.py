"""Store the Windows attendance device token encrypted for the current user."""

import ctypes
from ctypes import wintypes
from pathlib import Path

TOKEN_FILE = Path.home() / "AppData" / "Roaming" / "SWSAttendance" / "device_token.bin"
CRYPTPROTECT_UI_FORBIDDEN = 0x01


class _DataBlob(ctypes.Structure):
    _fields_ = [("cbData", wintypes.DWORD), ("pbData", ctypes.POINTER(ctypes.c_byte))]


def _windows_crypto():
    if not hasattr(ctypes, "WinDLL"):
        raise RuntimeError("Device tokens are stored using Windows DPAPI.")
    crypt32 = ctypes.WinDLL("crypt32", use_last_error=True)
    kernel32 = ctypes.WinDLL("kernel32", use_last_error=True)
    crypt32.CryptProtectData.restype = wintypes.BOOL
    crypt32.CryptProtectData.argtypes = [
        ctypes.POINTER(_DataBlob), wintypes.LPCWSTR, ctypes.POINTER(_DataBlob),
        wintypes.LPVOID, wintypes.LPVOID, wintypes.DWORD, ctypes.POINTER(_DataBlob),
    ]
    crypt32.CryptUnprotectData.restype = wintypes.BOOL
    crypt32.CryptUnprotectData.argtypes = [
        ctypes.POINTER(_DataBlob), ctypes.POINTER(wintypes.LPWSTR), ctypes.POINTER(_DataBlob),
        wintypes.LPVOID, wintypes.LPVOID, wintypes.DWORD, ctypes.POINTER(_DataBlob),
    ]
    kernel32.LocalFree.restype = wintypes.HLOCAL
    kernel32.LocalFree.argtypes = [wintypes.HLOCAL]
    return crypt32, kernel32


def _blob(data: bytes):
    buffer = ctypes.create_string_buffer(data)
    blob = _DataBlob(len(data), ctypes.cast(buffer, ctypes.POINTER(ctypes.c_byte)))
    return blob, buffer


def _crypt(data: bytes, protect: bool) -> bytes:
    crypt32, kernel32 = _windows_crypto()
    source, source_buffer = _blob(data)
    destination = _DataBlob()
    operation = crypt32.CryptProtectData if protect else crypt32.CryptUnprotectData
    if protect:
        ok = operation(ctypes.byref(source), "SWS Attendance device token", None, None, None,
                       CRYPTPROTECT_UI_FORBIDDEN, ctypes.byref(destination))
    else:
        ok = operation(ctypes.byref(source), None, None, None, None,
                       CRYPTPROTECT_UI_FORBIDDEN, ctypes.byref(destination))
    if not ok:
        raise ctypes.WinError(ctypes.get_last_error())
    try:
        return ctypes.string_at(destination.pbData, destination.cbData)
    finally:
        kernel32.LocalFree(destination.pbData)


def protect(data: bytes) -> bytes:
    """Encrypt bytes for the current Windows user (DPAPI)."""
    return _crypt(data, protect=True)


def unprotect(data: bytes) -> bytes:
    """Decrypt bytes previously protected for the current Windows user."""
    return _crypt(data, protect=False)


def load_token() -> str:
    if not TOKEN_FILE.exists():
        return ""
    return _crypt(TOKEN_FILE.read_bytes(), protect=False).decode("utf-8")


def save_token(token: str) -> None:
    if not token:
        raise ValueError("Cannot save an empty device token.")
    TOKEN_FILE.parent.mkdir(parents=True, exist_ok=True)
    encrypted = _crypt(token.encode("utf-8"), protect=True)
    temporary = TOKEN_FILE.with_suffix(".tmp")
    temporary.write_bytes(encrypted)
    temporary.replace(TOKEN_FILE)


def delete_token() -> None:
    TOKEN_FILE.unlink(missing_ok=True)
