/* Local QR generation: article URLs are never sent to an image or QR service. */
document.querySelectorAll('[data-pagenest-social-qr]').forEach((element) => {
  try {
    const code = qrcode(0, 'M');
    code.addData(element.dataset.pagenestSocialQr, 'Byte');
    code.make();
    const canvas = document.createElement('canvas');
    const count = code.getModuleCount();
    const cell = 4;
    canvas.width = canvas.height = (count + 8) * cell;
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', '文章链接二维码');
    const context = canvas.getContext('2d');
    context.fillStyle = '#fff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.fillStyle = '#000';
    for (let row = 0; row < count; row++) {
      for (let column = 0; column < count; column++) {
        if (code.isDark(row, column))
          context.fillRect((column + 4) * cell, (row + 4) * cell, cell, cell);
      }
    }
    element.append(canvas);
  } catch {
    element.textContent = '请复制文章链接到微信。';
  }
});
