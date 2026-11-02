#!/usr/bin/env python3
"""Import only the build outputs from a hosted runner into a private staging tree."""
import hashlib
import os
import shutil
import stat
import sys
import tarfile
import tempfile
from pathlib import Path, PurePosixPath

MIB = 1024 ** 2


def import_bundle(stream, dest, project, expected_digest):
    frontend = project == "front"
    if project not in ("front", "backend"):
        raise ValueError("unknown project")
    roots = {"node_modules", ".next"} if frontend else {"build"}
    expected = (".next/BUILD_ID", "node_modules/next/dist/bin/next") if frontend else ("build/manifest.json",)
    limit = (4096 if frontend else 128) * MIB
    # dest is a trusted root-owned release, never a path obtained from the archive.
    if any(os.path.lexists(dest / root) for root in roots) or dest.is_symlink():
        raise ValueError("build destination already populated")
    dest = dest.resolve(strict=True)
    with tempfile.TemporaryDirectory(dir=dest) as scratch:
        base = Path(scratch)
        names = set()
        directories = set()
        links = {}
        total = 0
        digest = hashlib.sha256()
        size = 0
        with tempfile.TemporaryFile(dir=base) as verified:
            while chunk := stream.read(1024 * 1024):
                size += len(chunk)
                if size > (1024 if frontend else 64) * MIB:
                    raise ValueError("compressed archive too large")
                digest.update(chunk)
                verified.write(chunk)
            if digest.hexdigest() != expected_digest:
                raise ValueError("archive differs from signed delivery")
            verified.seek(0)
            with tarfile.open(fileobj=verified, mode="r|gz") as archive:
                for member in archive:
                    name = PurePosixPath(member.name)
                    if (not name.parts or name.parts[0] not in roots or ".." in name.parts
                            or str(name) != member.name or len(member.name) > 4096 or len(name.parts) > 80
                            or name in names or len(names) >= (180000 if frontend else 4096)
                            or (len(name.parts) > 1 and name.parent not in directories)):
                        raise ValueError("unexpected build path")
                    names.add(name)
                    path = base / name
                    if member.isdir():
                        path.mkdir()
                        directories.add(name)
                        continue
                    if member.issym() and frontend:
                        if (len(name.parts) < 3 or not (name.parent.name == ".bin"
                                or name.parts[:2] == (".next", "node_modules"))
                                or not member.linkname or os.path.isabs(member.linkname)):
                            raise ValueError("unexpected build symlink")
                        links[path] = member.linkname
                        continue
                    total += member.size
                    if (not member.isfile() or member.issparse() or member.size < 0
                            or total > limit or (not frontend and name.suffix.lower() not in {
                                ".json", ".js", ".css", ".svg", ".png", ".webp", ".jpg",
                                ".jpeg", ".woff", ".woff2", ".ttf", ".ico", ".map", ".gif", ".avif"
                            })):
                        raise ValueError("unexpected build file or size")
                    with path.open("xb") as out:
                        shutil.copyfileobj(archive.extractfile(member), out, 1024 * 1024)
                    path.chmod(0o755 if frontend and member.mode & stat.S_IXUSR else 0o644)
        # No extraction or root-owned writes through archive-controlled links.
        for path, target in links.items():
            path.symlink_to(target)
        for path in links:
            if not path.resolve(strict=True).is_relative_to(base / "node_modules"):
                raise ValueError("build symlink leaves dependencies")
        for item in expected:
            path = base / item
            if path.is_symlink() or not path.is_file() or path.stat().st_size == 0:
                raise ValueError("missing required build output: " + item)
        for root in roots:
            (base / root).rename(dest / root)


if __name__ == "__main__":
    os.umask(0o022)
    import_bundle(sys.stdin.buffer, Path(sys.argv[2]).resolve(strict=True), sys.argv[1], sys.argv[3])
