# axe-core runtime asset

`npm run bundle:axe` copies the pinned axe-core 4.11.4 browser runtime and
its upstream license files into this directory. Generated release packages
must include:

- `axe.min.js`
- `LICENSE`
- `LICENSE-3RD-PARTY.txt`
- `VERSION`

Do not replace only the JavaScript file; update the pinned version, lock file,
version constant, and notices together.

