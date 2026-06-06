#!/bin/bash
set -euo pipefail

payload="$(cat)"
job_id="$(echo "$payload" | jq -r '.job.id')"
audio_url="$(echo "$payload" | jq -r '.job.assets.audio')"
complete_url="$(echo "$payload" | jq -r '.job.assets.complete')"
intro_title="$(echo "$payload" | jq -r '.job.intro_title // ""')"
intro_sec="$(echo "$payload" | jq -r '.job.spec.intro_sec // 6')"
music_start="$(echo "$payload" | jq -r '.job.spec.music_start_sec // 3')"
video_lead="$(echo "$payload" | jq -r '.job.spec.video_lead_sec // 3')"
fade_in="$(echo "$payload" | jq -r '.job.spec.fade_in_sec // 1.5')"
fade_out="$(echo "$payload" | jq -r '.job.spec.fade_out_sec // 2.5')"
slide_min="$(echo "$payload" | jq -r '.job.spec.slide_min_sec // 3')"
slide_max="$(echo "$payload" | jq -r '.job.spec.slide_max_sec // 5')"
width=1920
height=1080
fps=30

WORKDIR="${EFPIC_WORK_DIR:-/tmp/efpic-render}"
job_dir="${WORKDIR}/${job_id}"
mkdir -p "$job_dir/segments"

die() {
  echo "$*" >"${WORKDIR}/${job_id}.err"
  echo "$*" >&2
  exit 1
}

auth_header="Authorization: Bearer ${EFPIC_API_TOKEN:?}"

curl -sf -H "$auth_header" -o "${job_dir}/audio.mp3" "$audio_url" || die "Neizdevās lejupielādēt audio"

mapfile -t image_urls < <(echo "$payload" | jq -r '.job.assets.images[].url')
if [ "${#image_urls[@]}" -eq 0 ]; then
  die "Nav bildes"
fi

idx=1
for url in "${image_urls[@]}"; do
  num="$(printf '%04d' "$idx")"
  curl -sf -H "$auth_header" -L -o "${job_dir}/img_${num}.jpg" "$url" || die "Neizdevās lejupielādēt bildi ${idx}"
  idx=$((idx + 1))
done

audio_dur="$(ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "${job_dir}/audio.mp3" | awk '{printf "%.3f", $1}')"
if [ -z "$audio_dur" ] || [ "$(echo "$audio_dur <= 0" | bc -l)" -eq 1 ]; then
  die "Neizdevās nolasīt MP3 garumu"
fi

total_dur="$(echo "$video_lead + $audio_dur" | bc -l)"
slides_budget="$(echo "$total_dur - $intro_sec" | bc -l)"
if [ "$(echo "$slides_budget < 0" | bc -l)" -eq 1 ]; then
  slides_budget=0
fi
img_count="${#image_urls[@]}"
slide_dur="$(echo "scale=3; if ($img_count > 0) $slides_budget / $img_count else $slide_min" | bc -l)"
if [ "$(echo "$slide_dur < $slide_min" | bc -l)" -eq 1 ]; then
  slide_dur="$slide_min"
fi
if [ "$(echo "$slide_dur > $slide_max" | bc -l)" -eq 1 ]; then
  slide_dur="$slide_max"
fi

escape_drawtext() {
  local s="$1"
  s="${s//\\/\\\\}"
  s="${s//:/\\:}"
  s="${s//\'/\\'}"
  s="${s//%/\\%}"
  printf '%s' "$s"
}

title_line="$(escape_drawtext "$intro_title")"
if [[ "$intro_title" == *"+"* ]]; then
  title_line="$(escape_drawtext "$(echo "$intro_title" | sed 's/[[:space:]]*+[[:space:]]*/\\n/g')")"
fi

intro_file="${job_dir}/segments/intro.mp4"
if [ -n "$title_line" ]; then
  ffmpeg -y -f lavfi -i "color=c=white:s=${width}x${height}:d=${intro_sec}:r=${fps}" \
    -vf "drawtext=fontfile=/usr/share/fonts/dejavu/DejaVuSans.ttf:text='${title_line}':fontsize=64:fontcolor=black:x=(w-text_w)/2:y=(h-text_h)/2:enable='gte(t,1)'" \
    -c:v libx264 -pix_fmt yuv420p -t "$intro_sec" "$intro_file"
else
  ffmpeg -y -f lavfi -i "color=c=white:s=${width}x${height}:d=${intro_sec}:r=${fps}" \
    -c:v libx264 -pix_fmt yuv420p -t "$intro_sec" "$intro_file"
fi

seg_list="${job_dir}/segments/concat.txt"
: >"$seg_list"
printf "file '%s'\n" "$intro_file" >>"$seg_list"

idx=1
for _ in "${image_urls[@]}"; do
  num="$(printf '%04d' "$idx")"
  src="${job_dir}/img_${num}.jpg"
  seg="${job_dir}/segments/slide_${num}.mp4"
  ffmpeg -y -loop 1 -t "$slide_dur" -i "$src" \
    -vf "scale=${width}:${height}:force_original_aspect_ratio=decrease,pad=${width}:${height}:(ow-iw)/2:(oh-ih)/2:white,format=yuv420p" \
    -r "$fps" -c:v libx264 -pix_fmt yuv420p "$seg"
  printf "file '%s'\n" "$seg" >>"$seg_list"
  idx=$((idx + 1))
done

video_noaudio="${job_dir}/video_noaudio.mp4"
ffmpeg -y -f concat -safe 0 -i "$seg_list" -c copy "$video_noaudio"

fade_out_start="$(echo "$total_dur - $fade_out" | bc -l)"
if [ "$(echo "$fade_out_start < $music_start" | bc -l)" -eq 1 ]; then
  fade_out_start="$music_start"
fi

delay_ms="$(echo "$music_start * 1000" | bc | awk '{printf "%d", $1}')"
out_mp4="${job_dir}/slideshow.mp4"
ffmpeg -y -i "$video_noaudio" -i "${job_dir}/audio.mp3" \
  -filter_complex "[1:a]adelay=${delay_ms}|${delay_ms},afade=t=in:st=${music_start}:d=${fade_in},afade=t=out:st=${fade_out_start}:d=${fade_out}[a]" \
  -map 0:v:0 -map "[a]" -c:v libx264 -pix_fmt yuv420p -c:a aac -b:a 192k -t "$total_dur" "$out_mp4"

curl -sf -X POST -H "$auth_header" -F "video=@${out_mp4};type=video/mp4" "$complete_url" >/dev/null \
  || die "Neizdevās augšupielādēt MP4"

exit 0
