# C-Net Meet

Self-hosted audio/video meetings and webinars for MCI Educational Group.

## Locked product direction

- LiveKit SFU architecture with a 1,000-participant room ceiling.
- Audio Meeting, Video Meeting and Webinar modes.
- Webinar audiences subscribe by default; host/presenters publish media.
- No meeting-duration restriction; recording remains disabled.
- BigRock hosts the portal/token endpoint; Oracle/VPS hosts LiveKit, Redis and TURN.
- Production capacity is certified through staged 100/300/500/1000 load tests.

## Legacy shared-hosting release

- Audio only; camera is never requested.
- Maximum 10 active participants per room for controlled testing (8 recommended for the first test).
- No recording and no meeting duration limit.
- Host controls: mute, remove, lock, end for everyone.
- Raise hand, participant list, link sharing and text chat.
- PHP/file-based signalling compatible with BigRock shared cPanel.
- Rename, local participant pin, chat, hand raise, room lock, host mute/remove and end-meeting controls.
- Public visitors can join with an invite link or Meeting ID; creating, scheduling, and starting host meetings requires the admin-managed 4–6 digit Host Authentication PIN.
- Scheduled meetings cannot begin before the host starts them. Optional Waiting Room, Admit/Reject, Mute all, Unmute all, and Remove & restrict controls are available to the host.

## Requirements

PHP 8.2+, HTTPS, writable `storage/rooms` directory, and browser microphone permission.

The existing WebRTC mesh remains available until the VPS is connected. It is not the production transport for the locked 1,000-participant edition.

## LiveKit preparation included

- `livekit-token.php`: server-side access-token issuer; secrets never enter browser code.
- `config.example.php`: BigRock portal configuration template.
- `infrastructure/docker-compose.yml`: ARM64-compatible LiveKit and Redis services.
- `infrastructure/livekit.example.yaml`: 1,000-participant ceiling, TURN and UDP configuration.

Real `config.php` and `infrastructure/livekit.yaml` files are ignored by Git and must never be committed.
