"""Buduje dist/menedzer_crm_ai-X.Y.Z.zip z plików modułu śledzonych w git."""
import re
import subprocess
import zipfile
from pathlib import Path

root = Path(__file__).parent
main = (root / "menedzer_crm_ai" / "menedzer_crm_ai.php").read_text(encoding="utf-8")
version = re.search(r"^Version:\s*(\S+)", main, re.M).group(1)
constant = re.search(r"define\('MCAI_VERSION', '([^']+)'\)", main).group(1)
assert version == constant, f"Version {version} != MCAI_VERSION {constant}"

files = subprocess.run(["git", "ls-files", "menedzer_crm_ai"], cwd=root, capture_output=True, text=True, check=True).stdout.split()
assert "menedzer_crm_ai/menedzer_crm_ai.php" in files, "moduł nie jest w git"
(root / "dist").mkdir(exist_ok=True)
out = root / "dist" / f"menedzer_crm_ai-{version}.zip"
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for f in sorted(files):
        z.write(root / f, f)
print(out, len(files), "plików")
