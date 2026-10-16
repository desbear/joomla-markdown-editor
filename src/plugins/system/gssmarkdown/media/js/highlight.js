/**
 * GSS Markdown: highlights code blocks produced by the editor, and only those
 * (pre.gssmd-code), so other highlighters on the page are left alone.
 */
(() => {
  const run = () => {
    if (!window.Prism) {
      return;
    }

    document.querySelectorAll('pre.gssmd-code > code[class*="language-"]').forEach((el) => {
      window.Prism.highlightElement(el);
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
