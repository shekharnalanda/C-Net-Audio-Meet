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
- Maximum 8 active participants per room.
- No recording and no meeting duration limit.
- Host controls: mute, remove, lock, end for everyone.
- Raise hand, participant list, link sharing and text chat.
- PHP/file-based signalling compatible with BigRock shared cPanel.

## Requirements

PHP 8.2+, HTTPS, writable `storage/rooms` directory, and browser microphone permission.

The existing WebRTC mesh remains available until the VPS is connected. It is not the production transport for the locked 1,000-participant edition.

## LiveKit preparation included

- `livekit-token.php`: server-side access-token issuer; secrets never enter browser code.
- `config.example.php`: BigRock portal configuration template.
- `infrastructure/docker-compose.yml`: ARM64-compatible LiveKit and Redis services.
- `infrastructure/livekit.example.yaml`: 1,000-participant ceiling, TURN and UDP configuration.

Real `config.php` and `infrastructure/livekit.yaml` files are ignored by Git and must never be committed.

## Oracle Always Free micro test profile

`infrastructure/install-oracle-micro.sh` bootstraps the available
`VM.Standard.E2.1.Micro` instance with a 2 GB swap file, Docker, LiveKit and
Caddy-managed HTTPS for `livekit.meet.mciedu.com`. It deliberately limits the
room to 20 participants because the 1 OCPU / 1 GB instance is for functional
testing, not the locked 1,000-participant production target.

The generated API credentials stay on the VPS in
`/opt/cnet-meet-livekit/portal-config.php`. Never commit that file. Oracle's
VCN security list must separately allow TCP 80, 443 and 7881 plus UDP
50000-50100 before browser media testing.
