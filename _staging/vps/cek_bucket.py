#!/usr/bin/env python3
"""
Lihat isi bucket Kilat Storage (CloudKilat) dari baris perintah.

CloudKilat tidak menyediakan file browser di panel web, jadi skrip ini
memakai API S3-nya langsung lewat boto3 (sudah terpasang di
/root/telegram-env).

CARA PAKAI
----------
    source /root/telegram-env/bin/activate
    export AWS_ACCESS_KEY_ID="..."
    export AWS_SECRET_ACCESS_KEY="..."

    python3 cek_bucket.py                  # ringkasan seluruh bucket
    python3 cek_bucket.py telegram/        # hanya prefix tertentu
    python3 cek_bucket.py --list           # tampilkan semua objek
    python3 cek_bucket.py --list telegram/

Kredensial diambil dari environment variable, tidak pernah ditulis ke
file, supaya tidak ikut ter-backup atau ter-commit tanpa sengaja.
"""

import os
import sys
from collections import defaultdict

import boto3
from botocore.exceptions import ClientError, NoCredentialsError

ENDPOINT_URL = os.environ.get(
    "S3_ENDPOINT_URL",
    "https://s3-id-jkt-1.kilatstorage.id",
)

BUCKET = os.environ.get("S3_BUCKET", "dramaverse")

REGION = os.environ.get("S3_REGION", "us-east-1")


def format_size(num_bytes):
    if num_bytes >= 1024 ** 3:
        return f"{num_bytes / (1024 ** 3):.2f} GB"

    if num_bytes >= 1024 ** 2:
        return f"{num_bytes / (1024 ** 2):.1f} MB"

    if num_bytes >= 1024:
        return f"{num_bytes / 1024:.1f} KB"

    return f"{num_bytes} B"


def parse_args(argv):
    show_list = False
    prefix = ""

    for arg in argv[1:]:
        if arg in ("--list", "-l"):
            show_list = True
        elif arg in ("--help", "-h"):
            print(__doc__)
            sys.exit(0)
        else:
            prefix = arg

    return show_list, prefix


def main():
    show_list, prefix = parse_args(sys.argv)

    if not os.environ.get("AWS_ACCESS_KEY_ID"):
        print(
            "ERROR: AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY "
            "belum di-set.\n\n"
            '  export AWS_ACCESS_KEY_ID="..."\n'
            '  export AWS_SECRET_ACCESS_KEY="..."\n'
        )
        sys.exit(1)

    client = boto3.client(
        "s3",
        endpoint_url=ENDPOINT_URL,
        region_name=REGION,
    )

    print(f"Endpoint : {ENDPOINT_URL}")
    print(f"Bucket   : {BUCKET}")
    print(f"Prefix   : {prefix or '(seluruh bucket)'}\n")

    objects = []

    try:
        paginator = client.get_paginator("list_objects_v2")

        for page in paginator.paginate(
            Bucket=BUCKET,
            Prefix=prefix,
        ):
            objects.extend(page.get("Contents", []))

    except NoCredentialsError:
        print("ERROR: Kredensial tidak terbaca.")
        sys.exit(1)

    except ClientError as error:
        code = error.response.get("Error", {}).get("Code", "?")

        print(f"ERROR dari server ({code}): {error}")

        if code in ("NoSuchBucket",):
            print(
                f"\nBucket '{BUCKET}' tidak ditemukan. "
                "Set nama yang benar lewat S3_BUCKET."
            )

        if code in ("InvalidAccessKeyId", "SignatureDoesNotMatch"):
            print(
                "\nAccess key atau secret salah. "
                "Ambil ulang dari panel CloudKilat."
            )

        sys.exit(1)

    if not objects:
        print("Bucket kosong (atau prefix tidak cocok dengan apa pun).")
        return

    total_size = sum(obj["Size"] for obj in objects)

    # Kelompokkan berdasarkan folder tingkat pertama.
    folders = defaultdict(lambda: {"count": 0, "size": 0})

    for obj in objects:
        key = obj["Key"]

        folder = key.split("/")[0] + "/" if "/" in key else "(root)"

        folders[folder]["count"] += 1
        folders[folder]["size"] += obj["Size"]

    if show_list:
        for obj in sorted(objects, key=lambda item: item["Key"]):
            print(
                f"{format_size(obj['Size']):>10}  "
                f"{obj['LastModified']:%Y-%m-%d %H:%M}  "
                f"{obj['Key']}"
            )

        print()

    print("PER FOLDER")
    print("-" * 52)

    for folder, info in sorted(
        folders.items(),
        key=lambda item: item[1]["size"],
        reverse=True,
    ):
        print(
            f"{folder:<28} "
            f"{info['count']:>5} objek  "
            f"{format_size(info['size']):>10}"
        )

    print("-" * 52)
    print(
        f"{'TOTAL':<28} "
        f"{len(objects):>5} objek  "
        f"{format_size(total_size):>10}"
    )

    print("\n10 FILE TERBESAR")
    print("-" * 52)

    for obj in sorted(
        objects,
        key=lambda item: item["Size"],
        reverse=True,
    )[:10]:
        print(f"{format_size(obj['Size']):>10}  {obj['Key']}")


if __name__ == "__main__":
    main()
