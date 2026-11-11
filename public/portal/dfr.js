// Ajusta el reproductor de Drive al formato real del video (vertical u horizontal), usando la miniatura
document.querySelectorAll('.dfr[data-did]').forEach(el => {
  const im = new Image(); im.referrerPolicy = 'no-referrer';
  im.onload = () => { if (im.naturalWidth > 0 && im.naturalHeight > 0) el.style.aspectRatio = im.naturalWidth + ' / ' + im.naturalHeight; };
  im.src = 'https://drive.google.com/thumbnail?id=' + encodeURIComponent(el.dataset.did) + '&sz=w800';
});
