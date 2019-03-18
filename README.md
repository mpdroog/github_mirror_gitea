Sync GitHub to Gitea 
==================
Sync all repository's (including private) from GitHub to Gitea you
have access to with the given token.

Flow
- Github: Get all repo's you have access to (paginated)
- Gitea: Add repo X
- Gitea: Already exists? mirror-sync!

```bash
git clone https://github.com/mpdroog/github_mirror_gitea
cd github_mirror_gitea
cp _config.example.php config.php
vi config.php
...
php index.php
```

mirror not working?
Have a look in the logs, availably by default in `/var/lib/gitea/log/gitea.log`
