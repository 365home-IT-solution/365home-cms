#!/usr/bin/env bash
#
# Dump toàn bộ database ra file .sql.gz, kiểm tra file, (tuỳ chọn) khôi phục thử vào 1 database
# tạm để chắc bản backup dùng được, rồi xoá các bản quá hạn.
#
#   deploy/backup-database.sh                    dump + kiểm tra file + xoay vòng
#   deploy/backup-database.sh --verify-restore   như trên, thêm bước khôi phục thử
#   deploy/backup-database.sh --prune-only       chỉ xoay vòng, không dump
#
# Thông tin kết nối đọc từ .env của dự án (DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_PORT).
# Biến môi trường tuỳ chỉnh:
#   BACKUP_DIR     thư mục chứa file dump     (mặc định: ../media_365home/database cạnh dự án)
#   DB_CONTAINER   tên container MariaDB/MySQL → chạy dump/khôi phục qua `docker exec` (server)
#   DB_BIN_DIR     thư mục chứa mysqldump/mysql khi không có trong PATH (vd DBngin ở local)
#   DB_HOST        host để kết nối khi KHÔNG dùng DB_CONTAINER (mặc định 127.0.0.1 — không lấy
#                  DB_HOST trong .env vì trên server đó là host.docker.internal, chỉ đúng bên
#                  trong container app)
#   KEEP_DAILY / KEEP_WEEKLY / KEEP_MONTHLY   số bản giữ lại (mặc định 7 / 4 / 6)

set -euo pipefail
umask 077 # dump chứa CCCD, SĐT, mật khẩu hash → chỉ chủ sở hữu đọc được

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${ENV_FILE:-$PROJECT_DIR/.env}"

VERIFY_RESTORE=0
PRUNE_ONLY=0
for arg in "$@"; do
    case "$arg" in
        --verify-restore) VERIFY_RESTORE=1 ;;
        --prune-only)     PRUNE_ONLY=1 ;;
        *) echo "Tham số không hợp lệ: $arg" >&2; exit 2 ;;
    esac
done

env_value() {
    # Lấy giá trị cuối cùng của KEY trong .env, bỏ nháy bao quanh.
    local value
    value="$(grep -E "^$1=" "$ENV_FILE" 2>/dev/null | tail -n 1 | cut -d= -f2- || true)"
    value="${value%$'\r'}"
    value="${value%\"}"; value="${value#\"}"
    value="${value%\'}"; value="${value#\'}"
    printf '%s' "$value"
}

DB_NAME="${DB_DATABASE:-$(env_value DB_DATABASE)}"
DB_USER="${DB_USERNAME:-$(env_value DB_USERNAME)}"
DB_PASS="${DB_PASSWORD-$(env_value DB_PASSWORD)}"
DB_PORT="${DB_PORT:-$(env_value DB_PORT)}"
DB_HOST="${DB_HOST:-127.0.0.1}"
[ "$DB_PASS" = "null" ] && DB_PASS=""

BACKUP_DIR="${BACKUP_DIR:-$(dirname "$PROJECT_DIR")/media_365home/database}"
KEEP_DAILY="${KEEP_DAILY:-7}"
KEEP_WEEKLY="${KEEP_WEEKLY:-4}"
KEEP_MONTHLY="${KEEP_MONTHLY:-6}"

[ -n "$DB_NAME" ] || { echo "Không đọc được DB_DATABASE từ $ENV_FILE" >&2; exit 1; }

log() { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }
die() { log "LỖI: $*" >&2; exit 1; }

# --- Tìm lệnh dump / client -------------------------------------------------------------------

find_bin() {
    # $@ = các tên lệnh theo thứ tự ưu tiên; in ra đường dẫn dùng được đầu tiên.
    local name dir
    for name in "$@"; do
        if [ -n "${DB_BIN_DIR:-}" ] && [ -x "$DB_BIN_DIR/$name" ]; then
            printf '%s' "$DB_BIN_DIR/$name"; return 0
        fi
        if command -v "$name" >/dev/null 2>&1; then
            command -v "$name"; return 0
        fi
        # DBngin (macOS) không thêm lệnh vào PATH.
        for dir in /Users/Shared/DBngin/mysql/*/bin /Users/Shared/DBngin/mariadb/*/bin; do
            if [ -x "$dir/$name" ]; then printf '%s' "$dir/$name"; return 0; fi
        done
    done
    return 1
}

if [ -n "${DB_CONTAINER:-}" ]; then
    command -v docker >/dev/null 2>&1 || die "Đặt DB_CONTAINER nhưng không có lệnh docker"
    # Trong container: ưu tiên lệnh mariadb-*, không có thì dùng mysql*.
    DUMP_NAME="$(docker exec "$DB_CONTAINER" sh -c 'command -v mariadb-dump || command -v mysqldump')" || die "Container $DB_CONTAINER không có mariadb-dump/mysqldump"
    CLIENT_NAME="$(docker exec "$DB_CONTAINER" sh -c 'command -v mariadb || command -v mysql')" || die "Container $DB_CONTAINER không có mariadb/mysql"
    CONNECT_ARGS=(-u"$DB_USER")
    run_dump()   { docker exec -e MYSQL_PWD="$DB_PASS" "$DB_CONTAINER" "$DUMP_NAME" "${CONNECT_ARGS[@]}" "$@"; }
    run_client() { docker exec -i -e MYSQL_PWD="$DB_PASS" "$DB_CONTAINER" "$CLIENT_NAME" "${CONNECT_ARGS[@]}" "$@"; }
else
    DUMP_BIN="$(find_bin mariadb-dump mysqldump)" || die "Không tìm thấy mariadb-dump/mysqldump (đặt DB_BIN_DIR hoặc DB_CONTAINER)"
    CLIENT_BIN="$(find_bin mariadb mysql)" || die "Không tìm thấy mariadb/mysql (đặt DB_BIN_DIR hoặc DB_CONTAINER)"
    CONNECT_ARGS=(-h"$DB_HOST" -P"${DB_PORT:-3306}" -u"$DB_USER")
    # Mật khẩu truyền qua biến môi trường để không lộ trong danh sách tiến trình.
    run_dump()   { MYSQL_PWD="$DB_PASS" "$DUMP_BIN" "${CONNECT_ARGS[@]}" "$@"; }
    run_client() { MYSQL_PWD="$DB_PASS" "$CLIENT_BIN" "${CONNECT_ARGS[@]}" "$@"; }
fi

# --- Dump -------------------------------------------------------------------------------------

dump_database() {
    mkdir -p "$BACKUP_DIR"
    chmod 700 "$BACKUP_DIR"

    DUMP_FILE="$BACKUP_DIR/$DB_NAME-$(date '+%Y-%m-%d_%H%M%S').sql.gz"
    local temp="$DUMP_FILE.part"

    log "Dump database '$DB_NAME' → $DUMP_FILE"

    # Ghi ra file tạm rồi mới đổi tên: tiến trình chết giữa chừng thì không để lại file .sql.gz dở
    # trông như một bản backup hợp lệ. --single-transaction: dump nhất quán mà không khoá bảng.
    if ! run_dump --single-transaction --quick --routines --triggers --no-tablespaces \
            --default-character-set=utf8mb4 "$DB_NAME" | gzip -c > "$temp"; then
        rm -f "$temp"
        die "Dump thất bại"
    fi

    [ -s "$temp" ] || { rm -f "$temp"; die "File dump rỗng (0 byte)"; }
    gzip -t "$temp" || { rm -f "$temp"; die "File dump hỏng, không giải nén được"; }

    # mysqldump/mariadb-dump luôn kết thúc bằng dòng "-- Dump completed"; thiếu = bị cắt giữa chừng.
    if ! gzip -dc "$temp" | tail -n 5 | grep -q -- '-- Dump completed'; then
        rm -f "$temp"
        die "File dump bị cắt giữa chừng (thiếu dòng '-- Dump completed')"
    fi

    mv "$temp" "$DUMP_FILE"
    log "Dump xong: $(du -h "$DUMP_FILE" | cut -f1 | tr -d ' ')"
}

# --- Khôi phục thử ----------------------------------------------------------------------------

row_counts() {
    # In "tên_bảng<TAB>số_dòng" của mọi bảng trong database $1, đếm chính xác bằng COUNT(*).
    local db="$1" query
    query="$(run_client -N -B -e "SET SESSION group_concat_max_len = 10000000;
        SELECT GROUP_CONCAT(CONCAT('SELECT ''', table_name, ''', COUNT(*) FROM \`', table_name, '\`') ORDER BY table_name SEPARATOR ' UNION ALL ')
        FROM information_schema.tables WHERE table_schema = '$db' AND table_type = 'BASE TABLE';")"
    { [ -n "$query" ] && [ "$query" != "NULL" ]; } || return 0
    run_client -N -B "$db" -e "$query"
}

verify_restore() {
    local test_db="${DB_NAME}_restore_test"
    local work source_counts restored_counts
    [ "$test_db" != "$DB_NAME" ] || die "Tên database khôi phục thử trùng database gốc"

    log "Khôi phục thử vào database tạm '$test_db'"
    run_client -e "DROP DATABASE IF EXISTS \`$test_db\`; CREATE DATABASE \`$test_db\` CHARACTER SET utf8mb4;"

    if ! gzip -dc "$DUMP_FILE" | run_client --default-character-set=utf8mb4 "$test_db"; then
        run_client -e "DROP DATABASE IF EXISTS \`$test_db\`;"
        die "Nạp file dump vào '$test_db' thất bại"
    fi

    work="$(mktemp -d)"
    source_counts="$work/source"; restored_counts="$work/restored"
    row_counts "$DB_NAME" > "$source_counts"
    row_counts "$test_db" > "$restored_counts"
    run_client -e "DROP DATABASE IF EXISTS \`$test_db\`;"

    local tables_source tables_restored rows_restored
    tables_source="$(wc -l < "$source_counts" | tr -d ' ')"
    tables_restored="$(wc -l < "$restored_counts" | tr -d ' ')"
    rows_restored="$(awk -F'\t' '{ sum += $2 } END { print sum + 0 }' "$restored_counts")"

    log "Bản khôi phục: $tables_restored bảng, $rows_restored dòng (database gốc: $tables_source bảng)"

    # Thiếu/thừa bảng là lỗi thật. Lệch số dòng thì chỉ cảnh báo: trên server đang chạy, bảng có
    # thể nhận thêm dòng (đơn, log...) trong lúc dump nên lệch vài dòng là bình thường.
    if [ "$(cut -f1 "$source_counts")" != "$(cut -f1 "$restored_counts")" ]; then
        diff <(cut -f1 "$source_counts") <(cut -f1 "$restored_counts") || true
        rm -rf "$work"
        die "Danh sách bảng sau khi khôi phục KHÁC database gốc"
    fi

    if ! diff -q "$source_counts" "$restored_counts" >/dev/null; then
        log "CẢNH BÁO: số dòng lệch ở các bảng sau (gốc → khôi phục):"
        join -t "$(printf '\t')" "$source_counts" "$restored_counts" | awk -F'\t' '$2 != $3 { printf "    %s: %s → %s\n", $1, $2, $3 }'
    else
        log "Khôi phục thử OK: số dòng của cả $tables_restored bảng khớp database gốc"
    fi

    rm -rf "$work"
}

# --- Xoay vòng --------------------------------------------------------------------------------

week_of() {
    # YYYY-MM-DD → năm-tuần ISO; hỗ trợ cả date của GNU (Linux) lẫn BSD (macOS).
    date -d "$1" '+%G-%V' 2>/dev/null || date -j -f '%Y-%m-%d' "$1" '+%G-%V'
}

prune_backups() {
    local days=" " weeks=" " months=" "
    local day_count=0 week_count=0 month_count=0
    local file name day week month keep removed=0 kept=0

    [ -d "$BACKUP_DIR" ] || return 0

    # Duyệt từ mới đến cũ. Giữ: bản mới nhất của KEEP_DAILY ngày gần nhất, của KEEP_WEEKLY tuần
    # gần nhất và của KEEP_MONTHLY tháng gần nhất. Tên file có ngày giờ nên sort theo tên là đủ.
    while IFS= read -r file; do
        name="$(basename "$file")"
        day="$(printf '%s' "$name" | sed -n 's/.*-\([0-9]\{4\}-[0-9]\{2\}-[0-9]\{2\}\)_[0-9]\{6\}\.sql\.gz$/\1/p')"
        [ -n "$day" ] || continue # không đúng mẫu tên → không phải file của script, không đụng tới

        week="$(week_of "$day")"
        month="${day%-*}"
        keep=0

        case "$days" in *" $day "*) ;; *)
            if [ "$day_count" -lt "$KEEP_DAILY" ]; then days="$days$day "; day_count=$((day_count + 1)); keep=1; fi ;;
        esac
        case "$weeks" in *" $week "*) ;; *)
            if [ "$week_count" -lt "$KEEP_WEEKLY" ]; then weeks="$weeks$week "; week_count=$((week_count + 1)); keep=1; fi ;;
        esac
        case "$months" in *" $month "*) ;; *)
            if [ "$month_count" -lt "$KEEP_MONTHLY" ]; then months="$months$month "; month_count=$((month_count + 1)); keep=1; fi ;;
        esac

        if [ "$keep" -eq 1 ]; then
            kept=$((kept + 1))
        else
            rm -f "$file"
            removed=$((removed + 1))
        fi
    done < <(find "$BACKUP_DIR" -maxdepth 1 -type f -name "$DB_NAME-*.sql.gz" | sort -r)

    log "Xoay vòng: giữ $kept bản, xoá $removed bản quá hạn"
}

# ----------------------------------------------------------------------------------------------

if [ "$PRUNE_ONLY" -eq 0 ]; then
    dump_database
    [ "$VERIFY_RESTORE" -eq 1 ] && verify_restore
fi

prune_backups
log "Hoàn tất"
