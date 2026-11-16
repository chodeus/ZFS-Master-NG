// The Main page's Unraid sheets, or a Shadowbox page's (mirrors zfsmPopupStylesheets() in ZFSMHelpers.php), as the files a webGUI tree has.
const fs = require('node:fs');
const path = require('node:path');

module.exports = function sheets(styles, theme, context) {
  const popup = fs.existsSync(path.join(styles, 'default-popup.css')) ? ['default-popup.css'] : ['default-color-palette.css', 'default-base.css', `themes/${theme}.css`];
  const names = context === 'popup' ? ['default-fonts.css', 'font-awesome.css', ...popup] : ['default-fonts.css', 'default-cases.css', 'default-color-palette.css', 'default-base.css', 'default-dynamix.css',
    `default-${theme}.css`, `dynamix-${theme}.css`, `themes/${theme}.css`];
  return names.map(name => path.join(styles, name)).filter(fs.existsSync);
};
