// Generates the placeholder launcher icon PNGs used by flutter_launcher_icons.
//
//   dart run tool/generate_icon.dart && dart run flutter_launcher_icons
//
// Replace assets/icon/*.png with the real artwork when it is available.
import 'dart:io';

import 'package:image/image.dart' as img;

void main() {
  final navy = img.ColorRgba8(0x12, 0x24, 0x3E, 255);
  final gold = img.ColorRgba8(0xC9, 0x9A, 0x2E, 255);

  img.Image k(img.Image canvas, int x0, int y0, int size, img.Color color) {
    // A geometric "K": stem + two arms, as filled polygons.
    final s = size / 100.0;
    img.Point p(num x, num y) => img.Point(x0 + x * s, y0 + y * s);
    img.fillPolygon(canvas, color: color, vertices: [p(22, 14), p(38, 14), p(38, 86), p(22, 86)]);
    img.fillPolygon(canvas, color: color, vertices: [p(38, 50), p(66, 14), p(84, 14), p(50, 58), p(38, 64)]);
    img.fillPolygon(canvas, color: color, vertices: [p(46, 52), p(58, 44), p(86, 86), p(67, 86)]);
    return canvas;
  }

  // Full icon (iOS, legacy Android): navy background, gold tile, navy K.
  final full = img.Image(width: 1024, height: 1024, numChannels: 4);
  img.fill(full, color: navy);
  img.fillRect(full, x1: 152, y1: 152, x2: 872, y2: 872, color: gold, radius: 170);
  k(full, 232, 232, 560, navy);

  // Adaptive foreground (Android 8+): gold K on transparent, inside the safe zone.
  final fg = img.Image(width: 1024, height: 1024, numChannels: 4);
  img.fill(fg, color: img.ColorRgba8(0, 0, 0, 0));
  k(fg, 300, 300, 424, gold);

  Directory('assets/icon').createSync(recursive: true);
  File('assets/icon/icon.png').writeAsBytesSync(img.encodePng(full));
  File('assets/icon/icon_foreground.png').writeAsBytesSync(img.encodePng(fg));
  stdout.writeln('Wrote assets/icon/icon.png and assets/icon/icon_foreground.png');
}
