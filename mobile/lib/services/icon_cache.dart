import 'dart:typed_data';
import 'dart:async';
import 'dart:io';

import 'package:flutter/widgets.dart';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';

/// Topic logos kept on disk and decoded in memory, so the list shows them
/// on the first frame instead of downloading them at every launch.
///
/// Icon URLs carry a version (`?v=…`) that changes with the logo, so a
/// cached file never goes stale.
class IconCache {
  IconCache._();

  static Directory? _dir;
  static final _images = <String, ImageProvider>{};
  static final _loading = <String, Future<ImageProvider?>>{};

  static Future<void> _ensureDir() async {
    if (_dir != null) return;
    final dir = Directory(
      '${(await getApplicationCacheDirectory()).path}/topic_icons',
    );
    await dir.create(recursive: true);
    _dir = dir;
  }

  /// The decoded logo, when it is ready to paint synchronously.
  static ImageProvider? ready(String url) => _images[url];

  /// Loads the logo from disk, or from the network when [network] is true.
  static Future<ImageProvider?> load(String url, {bool network = true}) {
    if (_images[url] case final image?) return Future.value(image);
    return _loading[url] ??= _load(
      url,
      network,
    ).whenComplete(() => _loading.remove(url));
  }

  /// Decodes the logos already on disk; called before the first screen.
  static Future<void> warm(Iterable<String> urls) async {
    try {
      await Future.wait(urls.toSet().map((url) => load(url, network: false)))
          .timeout(const Duration(milliseconds: 800));
    } catch (_) {
      // A slow disk only delays the logos, never the app.
    }
  }

  static Future<ImageProvider?> _load(String url, bool network) async {
    try {
      await _ensureDir();
      final file = File('${_dir!.path}/${_key(url)}');
      List<int>? bytes;
      if (await file.exists()) {
        bytes = await file.readAsBytes();
      } else if (network) {
        final response = await http
            .get(Uri.parse(url))
            .timeout(const Duration(seconds: 15));
        if (response.statusCode == 200 && response.bodyBytes.isNotEmpty) {
          bytes = response.bodyBytes;
          await file.writeAsBytes(bytes, flush: true);
        }
      }
      if (bytes == null || bytes.isEmpty) return null;

      final image = MemoryImage(
        bytes is Uint8List ? bytes : Uint8List.fromList(bytes),
      );
      if (!await _decode(image)) {
        if (await file.exists()) await file.delete();
        return null;
      }
      return _images[url] = image;
    } catch (_) {
      return null;
    }
  }

  /// Decodes into Flutter's image cache, so the first paint is synchronous.
  static Future<bool> _decode(ImageProvider image) {
    final done = Completer<bool>();
    final stream = image.resolve(ImageConfiguration.empty);
    late final ImageStreamListener listener;
    listener = ImageStreamListener(
      (_, _) {
        if (!done.isCompleted) done.complete(true);
        stream.removeListener(listener);
      },
      onError: (_, _) {
        if (!done.isCompleted) done.complete(false);
        stream.removeListener(listener);
      },
    );
    stream.addListener(listener);
    return done.future;
  }

  /// Stable file name for a URL (FNV-1a 64 bits).
  static String _key(String url) {
    var hash = 0xcbf29ce484222325;
    for (final unit in url.codeUnits) {
      hash ^= unit;
      hash = (hash * 0x100000001b3) & 0xFFFFFFFFFFFFFFFF;
    }
    return hash.toUnsigned(64).toRadixString(16);
  }
}
