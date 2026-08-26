# Push checklist

Copy/overwrite these files in the root of your local `shift-carewash-net` repository:

- README.md
- LICENSE
- CONTRIBUTING.md
- SECURITY.md
- THIRD_PARTY_NOTICES.md
- config.sample.php

Then run:

```bash
git status
git add README.md LICENSE CONTRIBUTING.md SECURITY.md THIRD_PARTY_NOTICES.md config.sample.php
git commit -m "Prepare project for open source contributors"
git push origin master
```

After pushing, use the contents of `GITHUB_SETTINGS.md` to set the repository Description and Topics from GitHub's web interface.

Important:
- Do not add your real `config.php`.
- Before pushing screenshots later, remove employee names, facility/customer details, addresses, phone numbers, tokens, and other private data.
