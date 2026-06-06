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
bg_mode="$(echo "$payload" | jq -r '.job.bg_mode // "white"')"
page_bg_raw="$(echo "$payload" | jq -r '.job.page_bg_color // "#ffffff"')"
width=1920
height=1080
fps=30
gap=36

WORKDIR="${EFPIC_WORK_DIR:-/tmp/efpic-render}"
job_dir="${WORKDIR}/${job_id}"
mkdir -p "$job_dir/segments"

die() {
  echo "$*" >"${WORKDIR}/${job_id}.err"
  echo "$*" >&2
  exit 1
}

run_ffmpeg() {
  local step="$1"
  shift
  local err_file="${job_dir}/ffmpeg.err"
  if ! "$@" 2>"$err_file"; then
    local tail_err
    tail_err="$(tail -5 "$err_file" 2>/dev/null | tr '\n' ' ')"
    die "${step}: ${tail_err:-ffmpeg failed}"
  fi
}

auth_header="Authorization: Bearer ${EFPIC_API_TOKEN:?}"

resolve_bg_color() {
  if [ "$bg_mode" = "gallery" ]; then
    local hex="${page_bg_raw#\#}"
    hex="$(printf '%s' "$hex" | tr '[:upper:]' '[:lower:]')"
    if [[ "$hex" =~ ^[0-9a-f]{6}$ ]]; then
      printf '0x%s' "$hex"
      return
    fi
  fi
  printf 'white'
}

BG_COLOR="$(resolve_bg_color)"
CANVAS_COLOR="$BG_COLOR"
MAT_COLOR="white"

curl -sf -H "$auth_header" -o "${job_dir}/audio.mp3" "$audio_url" || die "Neizdevās lejupielādēt audio"

mapfile -t image_urls < <(echo "$payload" | jq -r '.job.assets.images[].url')
if [ "${#image_urls[@]}" -eq 0 ]; then
  die "Nav bildes"
fi

idx=1
for url in "${image_urls[@]}"; do
  num="$(printf '%04d' "$idx")"
  curl -sf -H "$auth_header" -L -o "${job_dir}/img_${num}.jpg" "$url" || die "Neizdevās lejupielādēt bildi ${idx}"
  if ! ffprobe -v error -select_streams v:0 -show_entries stream=width -of csv=p=0 "${job_dir}/img_${num}.jpg" >/dev/null 2>&1; then
    die "Bilde ${idx} nav derīgs attēls"
  fi
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

rand_scale_pct() {
  printf '%d' $((52 + RANDOM % 37))
}

pick_single_variant() {
  case $((RANDOM % 3)) in
    0) printf 'left' ;;
    1) printf 'center' ;;
    2) printf 'right' ;;
  esac
}

pick_double_variant() {
  case $((RANDOM % 3)) in
    0) printf 'equal' ;;
    1) printf 'big_left' ;;
    2) printf 'big_right' ;;
  esac
}

write_intro_textfile() {
  local raw="$1"
  local out="$2"
  raw="$(printf '%s' "$raw" | tr '[:lower:]' '[:upper:]')"
  : >"$out"
  if [ -z "$raw" ]; then
    return
  fi
  if [[ "$raw" == *"+"* ]]; then
    local part
    local IFS='+'
    read -ra parts <<< "$raw"
    local wrote=0
    for part in "${parts[@]}"; do
      part="$(printf '%s' "$part" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
      if [ -z "$part" ]; then
        continue
      fi
      if [ "$wrote" -gt 0 ]; then
        printf '+\n' >>"$out"
      fi
      printf '%s\n' "$part" >>"$out"
      wrote=$((wrote + 1))
    done
  else
    printf '%s\n' "$raw" >>"$out"
  fi
}

escape_drawtext() {
  local s="$1"
  local sq="'"
  s="${s//\\/\\\\}"
  s="${s//:/\\:}"
  s="${s//${sq}/\\${sq}}"
  s="${s//%/\\%}"
  printf '%s' "$s"
}

build_intro_drawtext_vf() {
  local textfile="$1"
  local -a lines=()
  mapfile -t lines <"$textfile"
  if [ "${#lines[@]}" -eq 0 ]; then
    return 1
  fi
  local line_step=$((intro_fontsize + 22))
  local total_h=$(( ${#lines[@]} * line_step ))
  local y0=$(( (height - total_h) / 2 + intro_fontsize ))
  local vf="" i=0 y=0 esc="" line=""
  for line in "${lines[@]}"; do
    esc="$(escape_drawtext "$line")"
    y=$((y0 + i * line_step))
    if [ -n "$vf" ]; then
      vf="${vf},"
    fi
    vf="${vf}drawtext=fontfile=${intro_font}:text='${esc}':fontsize=${intro_fontsize}:fontcolor=black:x=(w-text_w)/2:y=${y}:enable=gte(t\\,1)"
    i=$((i + 1))
  done
  printf '%s' "$vf"
}

plan_segments() {
  local remaining="$img_count"
  local pos=1
  local seg=0
  local triple_count=0
  local target_segs
  target_segs="$(echo "scale=0; if ($slides_budget > 0) ($slides_budget + 3.5) / 4 else 1" | bc)"
  if [ "$target_segs" -lt 1 ]; then
    target_segs=1
  fi
  if [ "$target_segs" -gt "$img_count" ]; then
    target_segs="$img_count"
  fi

  SEG_LAYOUTS=()
  SEG_IMAGES=()
  SEG_VARIANTS=()
  SEG_SCALES=()

  while [ "$remaining" -gt 0 ]; do
    local segs_left=$((target_segs - seg))
    if [ "$segs_left" -lt 1 ]; then
      segs_left=1
    fi
    local triple_max=$(( (target_segs * 20 + 99) / 100 ))
    local layout=1
    local need
    need="$(echo "scale=2; $remaining / $segs_left" | bc -l)"

    if [ "$remaining" -ge 3 ] && [ "$triple_count" -lt "$triple_max" ] && [ "$(echo "$need >= 2.2" | bc -l)" -eq 1 ]; then
      if [ $((RANDOM % 100)) -lt 35 ]; then
        layout=3
      elif [ "$remaining" -ge 2 ] && [ "$(echo "$need >= 1.5" | bc -l)" -eq 1 ]; then
        layout=2
      fi
    elif [ "$remaining" -ge 2 ] && [ "$(echo "$need >= 1.4" | bc -l)" -eq 1 ]; then
      layout=2
    fi

    if [ "$layout" -eq 3 ] && [ "$remaining" -lt 3 ]; then
      layout=2
    fi
    if [ "$layout" -eq 2 ] && [ "$remaining" -lt 2 ]; then
      layout=1
    fi
    if [ "$layout" -eq 3 ] && [ "$triple_count" -ge "$triple_max" ]; then
      layout=2
    fi

    local imgs=""
    local j=0
    while [ "$j" -lt "$layout" ]; do
      imgs="${imgs} $(printf '%04d' "$pos")"
      pos=$((pos + 1))
      remaining=$((remaining - 1))
      j=$((j + 1))
    done

    if [ "$layout" -eq 3 ]; then
      triple_count=$((triple_count + 1))
    fi

    local variant="equal"
    local scale
    scale="$(rand_scale_pct)"
    if [ "$layout" -eq 1 ]; then
      variant="$(pick_single_variant)"
    elif [ "$layout" -eq 2 ]; then
      variant="$(pick_double_variant)"
    fi

    SEG_LAYOUTS+=("$layout")
    SEG_IMAGES+=("${imgs# }")
    SEG_VARIANTS+=("$variant")
    SEG_SCALES+=("$scale")
    seg=$((seg + 1))
  done

  SEG_COUNT="$seg"
}

plan_segments

if [ "$SEG_COUNT" -lt 1 ]; then
  die "Nav segmentu"
fi

slide_dur="$(echo "scale=3; if ($SEG_COUNT > 0) $slides_budget / $SEG_COUNT else $slide_min" | bc -l)"
if [ -z "$slide_dur" ] || [ "$(echo "$slide_dur <= 0" | bc -l)" -eq 1 ]; then
  slide_dur="$slide_min"
fi
if [ "$(echo "$slide_dur < $slide_min" | bc -l)" -eq 1 ]; then
  slide_dur="$slide_min"
fi
if [ "$(echo "$slide_dur > $slide_max" | bc -l)" -eq 1 ]; then
  slide_dur="$slide_max"
fi

render_slide_segment() {
  local seg_num="$1"
  local layout="$2"
  local imgs="$3"
  local variant="$4"
  local scale_pct="$5"
  local out="$6"
  local -a files=()
  local f
  for f in $imgs; do
    files+=("${job_dir}/img_${f}.jpg")
  done

  local max_w=$((1680 * scale_pct / 100))
  local max_h=$((960 * scale_pct / 100))
  if [ "$max_w" -lt 420 ]; then max_w=420; fi
  if [ "$max_h" -lt 320 ]; then max_h=320; fi

  case "$layout" in
    1)
      local img="${files[0]}"
      local x_expr="(ow-iw)/2"
      local y_expr="(oh-ih)/2"
      case "$variant" in
        left)
          x_expr="36"
          y_expr="(oh-ih)/2+30"
          ;;
        right)
          x_expr="ow-iw-36"
          y_expr="(oh-ih)/2-30"
          ;;
        center)
          case $((RANDOM % 3)) in
            0) y_expr="(oh-ih)/2" ;;
            1) y_expr="(oh-ih)/2+40" ;;
            2) y_expr="(oh-ih)/2-40" ;;
          esac
          ;;
      esac
      local vf="scale=${max_w}:${max_h}:force_original_aspect_ratio=decrease,pad=${width}:${height}:${x_expr}:${y_expr}:color=${CANVAS_COLOR},format=yuv420p"
      run_ffmpeg "Slide ${seg_num}" ffmpeg -y -loop 1 -t "$slide_dur" -i "$img" -vf "$vf" -r "$fps" -c:v libx264 -pix_fmt yuv420p "$out"
      ;;
    2)
      local cell_h=$(( height * scale_pct / 100 ))
      if [ "$cell_h" -gt $((height - gap * 2)) ]; then
        cell_h=$((height - gap * 2))
      fi
      if [ "$cell_h" -lt 360 ]; then cell_h=360; fi
      local w1 w2
      case "$variant" in
        big_left)
          w1=$(( (width - gap * 3) * 58 / 100 ))
          w2=$(( width - gap * 3 - w1 ))
          ;;
        big_right)
          w2=$(( (width - gap * 3) * 58 / 100 ))
          w1=$(( width - gap * 3 - w2 ))
          ;;
        *)
          w1=$(( (width - gap * 3) / 2 ))
          w2="$w1"
          ;;
      esac
      if [ "$w1" -lt 240 ]; then w1=240; fi
      if [ "$w2" -lt 240 ]; then w2=240; fi
      local fc="[0:v]scale=${w1}:${cell_h}:force_original_aspect_ratio=decrease,pad=${w1}:${cell_h}:(ow-iw)/2:(oh-ih)/2:color=${MAT_COLOR}[a0];"
      fc+="[1:v]scale=${w2}:${cell_h}:force_original_aspect_ratio=decrease,pad=${w2}:${cell_h}:(ow-iw)/2:(oh-ih)/2:color=${MAT_COLOR}[a1];"
      fc+="[a0][a1]hstack=inputs=2[hs];"
      fc+="[hs]pad=${width}:${height}:(ow-iw)/2:(oh-ih)/2:color=${CANVAS_COLOR},format=yuv420p[vout]"
      run_ffmpeg "Slide ${seg_num}" ffmpeg -y -loop 1 -t "$slide_dur" -i "${files[0]}" -loop 1 -t "$slide_dur" -i "${files[1]}" \
        -filter_complex "$fc" -map "[vout]" -r "$fps" -c:v libx264 -pix_fmt yuv420p "$out"
      ;;
    3)
      local cell_w=$(( (width - gap * 4) / 3 ))
      local cell_h=$(( height - gap * 2 ))
      local s3
      s3="$(rand_scale_pct)"
      cell_h=$(( cell_h * s3 / 100 ))
      if [ "$cell_h" -lt 320 ]; then cell_h=320; fi
      if [ "$cell_w" -lt 240 ]; then cell_w=240; fi
      local fc="[0:v]scale=${cell_w}:${cell_h}:force_original_aspect_ratio=decrease,pad=${cell_w}:${cell_h}:(ow-iw)/2:(oh-ih)/2:color=${MAT_COLOR}[a0];"
      fc+="[1:v]scale=${cell_w}:${cell_h}:force_original_aspect_ratio=decrease,pad=${cell_w}:${cell_h}:(ow-iw)/2:(oh-ih)/2:color=${MAT_COLOR}[a1];"
      fc+="[2:v]scale=${cell_w}:${cell_h}:force_original_aspect_ratio=decrease,pad=${cell_w}:${cell_h}:(ow-iw)/2:(oh-ih)/2:color=${MAT_COLOR}[a2];"
      fc+="[a0][a1][a2]hstack=inputs=3[hs];"
      fc+="[hs]pad=${width}:${height}:(ow-iw)/2:(oh-ih)/2:color=${CANVAS_COLOR},format=yuv420p[vout]"
      run_ffmpeg "Slide ${seg_num}" ffmpeg -y -loop 1 -t "$slide_dur" -i "${files[0]}" -loop 1 -t "$slide_dur" -i "${files[1]}" -loop 1 -t "$slide_dur" -i "${files[2]}" \
        -filter_complex "$fc" -map "[vout]" -r "$fps" -c:v libx264 -pix_fmt yuv420p "$out"
      ;;
    *)
      die "Nezināms layout ${layout}"
      ;;
  esac
}

intro_font="/usr/share/fonts/dejavu/DejaVuSans.ttf"
if [ ! -f "$intro_font" ]; then
  intro_font="/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf"
fi
intro_fontsize=74
intro_textfile="${job_dir}/intro.txt"
write_intro_textfile "$intro_title" "$intro_textfile"

intro_file="${job_dir}/segments/intro.mp4"
intro_vf=""
if intro_vf="$(build_intro_drawtext_vf "$intro_textfile" 2>/dev/null)" && [ -n "$intro_vf" ]; then
  run_ffmpeg "Intro" ffmpeg -y -f lavfi -i "color=c=${BG_COLOR}:s=${width}x${height}:d=${intro_sec}:r=${fps}" \
    -vf "$intro_vf" -c:v libx264 -pix_fmt yuv420p -t "$intro_sec" "$intro_file"
else
  run_ffmpeg "Intro (tukšs)" ffmpeg -y -f lavfi -i "color=c=${BG_COLOR}:s=${width}x${height}:d=${intro_sec}:r=${fps}" \
    -c:v libx264 -pix_fmt yuv420p -t "$intro_sec" "$intro_file"
fi

seg_list="${job_dir}/segments/concat.txt"
: >"$seg_list"
printf "file '%s'\n" "$intro_file" >>"$seg_list"

seg_idx=0
while [ "$seg_idx" -lt "$SEG_COUNT" ]; do
  layout="${SEG_LAYOUTS[$seg_idx]}"
  imgs="${SEG_IMAGES[$seg_idx]}"
  variant="${SEG_VARIANTS[$seg_idx]}"
  scale="${SEG_SCALES[$seg_idx]}"
  seg="${job_dir}/segments/slide_$(printf '%04d' "$((seg_idx + 1))").mp4"
  render_slide_segment "$((seg_idx + 1))" "$layout" "$imgs" "$variant" "$scale" "$seg"
  printf "file '%s'\n" "$seg" >>"$seg_list"
  seg_idx=$((seg_idx + 1))
done

video_noaudio="${job_dir}/video_noaudio.mp4"
run_ffmpeg "Video concat" ffmpeg -y -f concat -safe 0 -i "$seg_list" -c:v libx264 -pix_fmt yuv420p -r "$fps" -movflags +faststart "$video_noaudio"

fade_out_start="$(echo "$total_dur - $fade_out" | bc -l)"
if [ "$(echo "$fade_out_start < $music_start" | bc -l)" -eq 1 ]; then
  fade_out_start="$music_start"
fi
fade_out_d="$fade_out"
fade_remain="$(echo "$total_dur - $fade_out_start" | bc -l)"
if [ "$(echo "$fade_remain < $fade_out_d" | bc -l)" -eq 1 ]; then
  fade_out_d="$(echo "if ($fade_remain > 0.2) $fade_remain else 0.2" | bc -l)"
fi

delay_ms="$(echo "$music_start * 1000" | bc | awk '{printf "%d", $1}')"
out_mp4="${job_dir}/slideshow.mp4"
run_ffmpeg "Audio mux" ffmpeg -y -i "$video_noaudio" -i "${job_dir}/audio.mp3" \
  -filter_complex "[1:a]adelay=${delay_ms}|${delay_ms},afade=t=in:st=${music_start}:d=${fade_in},afade=t=out:st=${fade_out_start}:d=${fade_out_d}[a]" \
  -map 0:v:0 -c:v copy -map "[a]" -c:a aac -b:a 192k -movflags +faststart -t "$total_dur" "$out_mp4"

curl -sf -X POST -H "$auth_header" -F "video=@${out_mp4};type=video/mp4" "$complete_url" >/dev/null || die "Neizdevās augšupielādēt MP4"

exit 0
