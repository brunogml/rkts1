import requests
import os

output_dir = "berlin_images"
os.makedirs(output_dir, exist_ok=True)

base_url = "https://img.toyobunko-lab.jp/iiif/tibetan/manuscript_kanjur/055/55_{}.tif/full/full/0/default.jpg"

for i in range(1, 1001):
    padded = str(i).zfill(4)
    url = base_url.format(padded)
    filename = os.path.join(output_dir, f"{padded}.jpg")

    if os.path.exists(filename):
        print(f"[SKIP] {padded}.jpg already exists")
        continue

    try:
        response = requests.get(url, timeout=30)
        if response.status_code == 200:
            with open(filename, "wb") as f:
                f.write(response.content)
            print(f"[OK]   {padded}.jpg")
        elif response.status_code == 404:
            print(f"[404]  {padded}.jpg — stopping")
            break
        else:
            print(f"[{response.status_code}] {padded}.jpg — skipping")
    except requests.RequestException as e:
        print(f"[ERR]  {padded}.jpg — {e}")