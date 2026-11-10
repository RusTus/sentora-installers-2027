# Snuffleupagus, the PHP hardening extension (https://snuffleupagus.readthedocs.io), for Sentora's
# PHP: built here, never switched on here (the PHP Hardening add-on does that, with its rules).
# Sourced by build-sentora-php.sh (every bundle) and build-snuffleupagus-system.sh (the system's
# own PHP).
#
# The source is the release tag, checked against its commit: a new release means a new
# SP_VERSION and SP_COMMIT here (git ls-remote https://github.com/jvoisin/snuffleupagus.git
# refs/tags/v<version>^{} gives the commit).

SP_VERSION=0.14.0
SP_COMMIT=1d8614af85a1baa52656f3adbd4684772df91288

# sp_build <work folder> <phpize> <php-config>
# Builds snuffleupagus.so for that PHP and installs it in its extension folder.
sp_build() {
    local work="$1" phpize="$2" phpconfig="$3"
    local src="$work/snuffleupagus" log="$work/snuffleupagus.log"
    echo "Building Snuffleupagus $SP_VERSION (log: $log)"
    rm -rf "$src"
    if ! git clone -q --depth 1 --branch "v$SP_VERSION" https://github.com/jvoisin/snuffleupagus.git "$src" > "$log" 2>&1; then
        tail -5 "$log"
        return 1
    fi
    if [ "$(git -C "$src" rev-parse HEAD)" != "$SP_COMMIT" ]; then
        echo "Snuffleupagus v$SP_VERSION is not the commit it should be ($SP_COMMIT). Stopping."
        return 1
    fi
    ( cd "$src/src" && "$phpize" && ./configure --with-php-config="$phpconfig" --enable-snuffleupagus \
        && make -j"$(nproc)" && make install ) >> "$log" 2>&1 || { tail -25 "$log"; return 1; }
}

# sp_check <php binary> <extension folder>
# Does snuffleupagus.so load into that PHP (with an empty rules file)?
sp_check() {
    local rules
    rules=$(mktemp)
    "$1" -n -d extension_dir="$2" -d extension=snuffleupagus.so -d sp.configuration_file="$rules" \
        -r 'exit(extension_loaded("snuffleupagus") ? 0 : 1);' > /dev/null 2>&1
    local r=$?
    rm -f "$rules"
    return $r
}
