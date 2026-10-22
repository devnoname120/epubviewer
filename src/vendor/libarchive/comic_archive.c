#define _FILE_OFFSET_BITS 64

/* SPDX-FileCopyrightText: 2026 Paul */
/* SPDX-License-Identifier: AGPL-3.0-or-later */

#include <archive.h>
#include <archive_entry.h>
#include <emscripten.h>
#include <limits.h>
#include <locale.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>

struct comic_archive {
    struct archive *archive;
    struct archive_entry *entry;
};

static char last_error[256];

static void set_error(struct archive *archive, const char *fallback) {
    const char *message = archive == NULL ? NULL : archive_error_string(archive);
    snprintf(last_error, sizeof(last_error), "%s", message == NULL ? fallback : message);
}

EMSCRIPTEN_KEEPALIVE
uintptr_t comic_archive_open(const unsigned char *data, size_t size) {
    /* RAR Unicode filenames must not be converted through the default C locale. */
    if (setlocale(LC_CTYPE, "C.UTF-8") == NULL) {
        set_error(NULL, "Could not enable UTF-8 archive filenames.");
        return 0;
    }
    struct comic_archive *handle = calloc(1, sizeof(*handle));
    if (handle == NULL) {
        set_error(NULL, "Could not allocate the archive handle.");
        return 0;
    }

    handle->archive = archive_read_new();
    if (handle->archive == NULL) {
        set_error(NULL, "Could not allocate the archive reader.");
        free(handle);
        return 0;
    }
    if (archive_read_support_format_rar(handle->archive) != ARCHIVE_OK
        || archive_read_support_format_rar5(handle->archive) != ARCHIVE_OK) {
        set_error(handle->archive, "Could not enable RAR support.");
        archive_read_free(handle->archive);
        free(handle);
        return 0;
    }
    if (archive_read_open_memory(handle->archive, data, size) != ARCHIVE_OK) {
        set_error(handle->archive, "Could not open the RAR entry.");
        archive_read_free(handle->archive);
        free(handle);
        return 0;
    }

    last_error[0] = '\0';
    return (uintptr_t)handle;
}

EMSCRIPTEN_KEEPALIVE
int comic_archive_next(uintptr_t raw_handle) {
    struct comic_archive *handle = (struct comic_archive *)raw_handle;
    int result = archive_read_next_header(handle->archive, &handle->entry);
    if (result == ARCHIVE_EOF) {
        handle->entry = NULL;
        return 0;
    }
    if (result != ARCHIVE_OK) {
        set_error(handle->archive, "Could not read the RAR entry header.");
        handle->entry = NULL;
        return -1;
    }
    return 1;
}

EMSCRIPTEN_KEEPALIVE
const char *comic_archive_entry_path(uintptr_t raw_handle) {
    struct comic_archive *handle = (struct comic_archive *)raw_handle;
    return handle->entry == NULL ? NULL : archive_entry_pathname_utf8(handle->entry);
}

EMSCRIPTEN_KEEPALIVE
int comic_archive_entry_size(uintptr_t raw_handle) {
    struct comic_archive *handle = (struct comic_archive *)raw_handle;
    if (handle->entry == NULL) {
        set_error(handle->archive, "No RAR entry is selected.");
        return -1;
    }
    la_int64_t size = archive_entry_size(handle->entry);
    if (size < 0 || size > INT_MAX) {
        set_error(handle->archive, "The RAR entry size is unsupported.");
        return -1;
    }
    return (int)size;
}

EMSCRIPTEN_KEEPALIVE
int comic_archive_entry_is_regular(uintptr_t raw_handle) {
    struct comic_archive *handle = (struct comic_archive *)raw_handle;
    return handle->entry != NULL && archive_entry_filetype(handle->entry) == AE_IFREG;
}

EMSCRIPTEN_KEEPALIVE
int comic_archive_entry_is_encrypted(uintptr_t raw_handle) {
    struct comic_archive *handle = (struct comic_archive *)raw_handle;
    return handle->entry == NULL ? 1 : archive_entry_is_encrypted(handle->entry) > 0;
}

EMSCRIPTEN_KEEPALIVE
int comic_archive_read_exact(uintptr_t raw_handle, unsigned char *output, size_t expected) {
    struct comic_archive *handle = (struct comic_archive *)raw_handle;
    size_t offset = 0;
    while (offset < expected) {
        la_ssize_t count = archive_read_data(handle->archive, output + offset, expected - offset);
        if (count <= 0) {
            set_error(handle->archive, count == 0 ? "The RAR entry is truncated." : "Could not decode the RAR entry.");
            return 0;
        }
        offset += (size_t)count;
    }

    unsigned char extra;
    la_ssize_t trailing = archive_read_data(handle->archive, &extra, 1);
    if (trailing < 0) {
        set_error(handle->archive, "Could not finish decoding the RAR entry.");
        return 0;
    }
    if (trailing != 0) {
        set_error(handle->archive, "The RAR entry exceeds its declared size.");
        return 0;
    }
    return 1;
}

EMSCRIPTEN_KEEPALIVE
void comic_archive_close(uintptr_t raw_handle) {
    struct comic_archive *handle = (struct comic_archive *)raw_handle;
    if (handle == NULL) {
        return;
    }
    archive_read_free(handle->archive);
    free(handle);
}

EMSCRIPTEN_KEEPALIVE
int comic_archive_version_number(void) {
    return archive_version_number();
}

EMSCRIPTEN_KEEPALIVE
const char *comic_archive_last_error(void) {
    return last_error;
}
