// Builds the launcher icon PNGs from the company mark uploaded in the web app (Settings > Branding),
// saved as assets/branding/favicon.png (the AH hexagon) and assets/branding/logo.png (full logo).
//
//   dart run tool/generate_icon.dart && dart run flutter_launcher_icons
//
// To rebrand: replace the two files in assets/branding/ with the new uploads and run the command above.
import 'dart:io';

import 'package:image/image.dart' as img;

/// The mark trimmed of transparent / near-white margins.
img.Image _trimmedMark() {
  final src = img.decodePng(File('assets/branding/favicon.png').readAsBytesSync())!;
  var minX = src.width, minY = src.height, maxX = 0, maxY = 0;
  for (final p in src) {
    final visible = p.a > 24 && !(p.r > 245 && p.g > 245 && p.b > 245);
    if (visible) {
      if (p.x < minX) minX = p.x;
      if (p.y < minY) minY = p.y;
      if (p.x > maxX) maxX = p.x;
      if (p.y > maxY) maxY = p.y;
    }
  }
  return img.copyCrop(src, x: minX, y: minY, width: maxX - minX + 1, height: maxY - minY + 1);
}

/// [mark] centred on a [size] square canvas, scaled to fill [fraction] of it.
img.Image _place(img.Image mark, int size, double fraction, img.Color background) {
  final canvas = img.Image(width: size, height: size, numChannels: 4);
  img.fill(canvas, color: background);
  final box = (size * fraction).round();
  final scale = box / (mark.width > mark.height ? mark.width : mark.height);
  final w = (mark.width * scale).round(), h = (mark.height * scale).round();
  final resized = img.copyResize(mark, width: w, height: h, interpolation: img.Interpolation.cubic);
  img.compositeImage(canvas, resized, dstX: (size - w) ~/ 2, dstY: (size - h) ~/ 2);
  return canvas;
}

void main() {
  final mark = _trimmedMark();
  final white = img.ColorRgba8(255, 255, 255, 255);
  final clear = img.ColorRgba8(0, 0, 0, 0);

  // Full icon (iOS, older Android): the mark on white with breathing room.
  final full = _place(mark, 1024, 0.72, white);
  // Adaptive foreground (Android 8+): launchers crop to the central ~66%, so keep the mark inside it.
  final fg = _place(mark, 1024, 0.56, clear);

  Directory('assets/icon').createSync(recursive: true);
  File('assets/icon/icon.png').writeAsBytesSync(img.encodePng(full));
  File('assets/icon/icon_foreground.png').writeAsBytesSync(img.encodePng(fg));
  stdout.writeln('Wrote assets/icon/icon.png and assets/icon/icon_foreground.png from assets/branding/favicon.png');
}
