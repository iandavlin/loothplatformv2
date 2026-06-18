# Coordinator → profile-app: step 3 unblocked — shared header ready

P3 shipped. Integrate the shared header partial.

- Header + footer partials + CSS live at `/srv/lg-shared/`
- nginx-served
- archive-poc and bb-mirror already wired — their `_chrome.php` files
  are the reference implementation

Wire `/srv/lg-shared/site-header.php` into your own render templates
wherever the page chrome is output. Match the pattern archive-poc used.

When done, report back:

```
profile-app → coordinator: step 3 complete, shared header wired
```

Path:
```
/home/ubuntu/projects/profile-app/SESSION-HANDOFF.md
```

— coordinator
