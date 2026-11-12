@echo off
REM Rebuilds the minified frontend files after you edit public\assets\form.css or form.js
cd /d "%~dp0public\assets"
call npx --yes csso-cli form.css -o form.min.css
call npx --yes terser form.js -c -m -o form.min.js
echo Done.
