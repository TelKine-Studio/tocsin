import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../l10n/strings.dart';
import '../services/pairing_link.dart';

/// Camera scanning the "Add on mobile" QR code of a topic page; pops with
/// the decoded [PairingLink].
class ScanScreen extends StatefulWidget {
  const ScanScreen({super.key});

  @override
  State<ScanScreen> createState() => _ScanScreenState();
}

class _ScanScreenState extends State<ScanScreen> {
  final _controller = MobileScannerController(
    formats: const [BarcodeFormat.qrCode],
  );
  bool _done = false;
  bool _invalid = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _onDetect(BarcodeCapture capture) {
    if (_done) return;
    for (final barcode in capture.barcodes) {
      final link = PairingLink.parse(barcode.rawValue ?? '');
      if (link != null) {
        _done = true;
        Navigator.of(context).pop(link);
        return;
      }
    }
    if (!_invalid) setState(() => _invalid = true);
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    const frame = 250.0;

    return Scaffold(
      backgroundColor: Colors.black,
      body: Stack(
        fit: StackFit.expand,
        children: [
          MobileScanner(
            controller: _controller,
            onDetect: _onDetect,
            errorBuilder: (context, error) => Center(
              child: Padding(
                padding: const EdgeInsets.all(32),
                child: Text(
                  s.t('camera_denied'),
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Colors.white, fontSize: 16),
                ),
              ),
            ),
          ),
          // Darkened surroundings with a clear square in the middle.
          IgnorePointer(
            child: ColorFiltered(
              colorFilter: ColorFilter.mode(
                Colors.black.withValues(alpha: 0.55),
                BlendMode.srcOut,
              ),
              child: Stack(
                fit: StackFit.expand,
                children: [
                  Container(color: Colors.transparent),
                  Center(
                    child: Container(
                      width: frame,
                      height: frame,
                      decoration: BoxDecoration(
                        color: Colors.black,
                        borderRadius: BorderRadius.circular(32),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
          IgnorePointer(
            child: Center(
              child: Container(
                width: frame,
                height: frame,
                decoration: BoxDecoration(
                  border: Border.all(color: Colors.white, width: 3),
                  borderRadius: BorderRadius.circular(32),
                ),
              ),
            ),
          ),
          SafeArea(
            child: Column(
              children: [
                Align(
                  alignment: AlignmentDirectional.centerStart,
                  child: Padding(
                    padding: const EdgeInsets.all(8),
                    child: IconButton(
                      tooltip: s.t('cancel'),
                      onPressed: () => Navigator.of(context).pop(),
                      icon: const Icon(Icons.close_rounded, size: 28),
                      color: Colors.white,
                    ),
                  ),
                ),
                const Spacer(),
                Padding(
                  padding: const EdgeInsets.fromLTRB(32, 0, 32, 48),
                  child: Column(
                    children: [
                      Text(
                        s.t('scan_qr'),
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 20,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        _invalid ? s.t('qr_invalid') : s.t('scan_hint'),
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          color: _invalid
                              ? const Color(0xFFFBBF24)
                              : Colors.white.withValues(alpha: 0.8),
                          fontSize: 14,
                          height: 1.4,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
