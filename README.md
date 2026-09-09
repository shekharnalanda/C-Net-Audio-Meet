# C-Net Audio Meet

Private, audio-only browser meetings for MCI Educational Group.

## Current release

- Audio only; camera is never requested.
- Maximum 8 active participants per room.
- No recording and no meeting duration limit.
- Host controls: mute, remove, lock, end for everyone.
- Raise hand, participant list, link sharing and text chat.
- PHP/file-based signalling compatible with BigRock shared cPanel.

## Requirements

PHP 8.2+, HTTPS, writable `storage/rooms` directory, and browser microphone permission.

This first shared-hosting edition uses a WebRTC mesh. For larger rooms, retain the UI/API and replace the transport with LiveKit SFU/TURN infrastructure.

