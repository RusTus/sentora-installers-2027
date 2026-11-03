#!/usr/bin/env bash

# Official Sentora Automated Installation Script
# =============================================
#
#  This program is free software: you can redistribute it and/or modify
#  it under the terms of the GNU General Public License as published by
#  the Free Software Foundation, either version 3 of the License, or
#  (at your option) any later version.
#
#  This program is distributed in the hope that it will be useful,
#  but WITHOUT ANY WARRANTY; without even the implied warranty of
#  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#  GNU General Public License for more details.
#
#  You should have received a copy of the GNU General Public License
#  along with this program.  If not, see <http://www.gnu.org/licenses/>.
#
# Supported Operating Systems:
# Ubuntu server 26.04 only (64bit)
#
# Contributions from:
#
#   Anthony DeBeaulieu (anthony.d@sentora.org)
#   TGagtes 
#   Pascal Peyremorte (ppeyremorte@sentora.org)
#   Mehdi Blagui
#   Kevin Andrews (kevin@zvps.uk)
#
#   and all those who participated to this and to previous installers.
#   Thanks to all.

## 
# SENTORA_CORE/INSTALLER_VERSION
# master - latest unstable
# 2.1.0 - example stable tag
##

SENTORA_INSTALLER_VERSION="master"
SENTORA_CORE_VERSION="2.0.2"

# Sentora is downloaded from the internal server instead of GitHub.
# Change these if the files are renamed or moved.
SENTORA_DOWNLOAD_URL="http://hembackup1.hemsaw.local/installers"
SENTORA_CORE_ZIP="sentora-core-2_0_2.zip"
SENTORA_INSTALLERS_ZIP="sentora-installers-master.zip"

PANEL_PATH="/etc/sentora"
PANEL_DATA="/var/sentora"
PANEL_CONF="/etc/sentora/configs"

#--- Display the 'welcome' splash/user warning info..
echo ""
echo "############################################################"
echo "#  Welcome to the Official Sentora Installer v.$SENTORA_INSTALLER_VERSION  #"
echo "############################################################"

echo -e "\nChecking that minimal requirements are ok"

# Ensure the OS is compatible with the launcher
# leave CentOS code..
if [ -f /etc/centos-release ]; then
    OS="CentOs"
    VERFULL=$(sed 's/^.*release //;s/ (Fin.*$//' /etc/centos-release)
    VER=${VERFULL:0:1} # return 8
elif [ -f /etc/lsb-release ]; then
    OS=$(grep DISTRIB_ID /etc/lsb-release | sed 's/^.*=//')
    VER=$(grep DISTRIB_RELEASE /etc/lsb-release | sed 's/^.*=//')
elif [ -f /etc/os-release ]; then
    OS=$(grep -w ID /etc/os-release | sed 's/^.*=//')
    VER=$(grep VERSION_ID /etc/os-release | sed 's/^.*"\(.*\)"/\1/')
 else
    OS=$(uname -s)
    VER=$(uname -r)
fi
ARCH=$(uname -m)

echo "Detected : $OS  $VER  $ARCH"

# This build is made and tested for Ubuntu 26.04 only: its Dovecot 2.4, PHP 8.5,
# MySQL 8.4 and BIND 9.20 configs do not work on older releases.
if [[ "$OS" = "Ubuntu" && "$VER" = "26.04" ]] ; then
    echo "Ok."
else
    echo "Sorry, this installer only supports Ubuntu 26.04 (detected: $OS $VER)."
    exit 1
fi

# Centos uses repo directory that depends of architecture. Ensure it is compatible
# leave CentOS code..
if [[ "$OS" = "CentOs" ]] ; then
    if [[ "$ARCH" == "i386" || "$ARCH" == "i486" || "$ARCH" == "i586" || "$ARCH" == "i686" ]]; then
        ARCH="i386"
    elif [[ "$ARCH" != "x86_64" ]]; then
        echo "Unexpected architecture name was returned ($ARCH ). :-("
        echo "The installer have been designed for i[3-6]8- and x86_64' architectures. If you"
        echo " think it may work on your, please report it to the Sentora forum or bugtracker."
        exit 1
    fi
fi

# Check if the user is 'root' before allowing installation to commence
if [ $UID -ne 0 ]; then
    echo "Install failed: you must be logged in as 'root' to install."
    echo "Use command 'sudo -i', then enter root password and then try again."
    exit 1
fi

# Check for some common control panels that we know will affect the installation/operating of Sentora.
if [ -e /usr/local/cpanel ] || [ -e /usr/local/directadmin ] || [ -e /usr/local/solusvm/www ] || [ -e /usr/local/home/admispconfig ] || [ -e /usr/local/lxlabs/kloxo ] ; then
    echo "It appears that a control panel is already installed on your server; This installer"
    echo "is designed to install and configure Sentora on a clean OS installation only."
    echo -e "\nPlease re-install your OS before attempting to install using this script."
    exit 1
fi

# Check for some common packages that we know will affect the installation/operating of Sentora.
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
	if [[ "$VER" = "24.04" || "$VER" = "26.04" ]]; then

		PACKAGE_INSTALLER="apt-get -yqq install"
	
		inst() {
		   dpkg -l "$1" 2> /dev/null | grep '^ii' &> /dev/null
		}
		
		DB_PCKG="mysql-server"
		HTTP_PCKG="apache2"

	elif [[ "$VER" = "12" ]]; then
	
		PACKAGE_INSTALLER="apt-get -yqq install"
	
		inst() {
		   dpkg -l "$1" 2> /dev/null | grep '^ii' &> /dev/null
		}
		
		DB_PCKG="default-mysql-server"
		HTTP_PCKG="apache2"
	
	fi
fi

# Install only the packages that the configured repositories actually provide.
# apt-get aborts the whole command when one name is unknown, so a single package
# dropped from a newer release (e.g. Ubuntu 26.04) would otherwise skip all the others.
install_available() {
    local pkg cand
    local avail=()
    local missing=()
    for pkg in "$@"; do
        cand=$(apt-cache policy "$pkg" 2> /dev/null | awk '/Candidate:/ {print $2}')
        if [[ -n "$cand" && "$cand" != "(none)" ]]; then
            avail+=("$pkg")
        else
            missing+=("$pkg")
        fi
    done
    if [ ${#missing[@]} -gt 0 ]; then
        echo "WARNING: not available in the configured repositories, skipped: ${missing[*]}"
    fi
    if [ ${#avail[@]} -gt 0 ]; then
        $PACKAGE_INSTALLER "${avail[@]}"
    fi
}

# Read the system timezone. Newer tzdata (Ubuntu 26.04, Debian 13) no longer
# maintains /etc/timezone, so check /etc/localtime and timedatectl first.
get_timezone() {
    local t=""
    if [ -L /etc/localtime ]; then
        t=$(readlink -f /etc/localtime | sed 's|^/usr/share/zoneinfo/||')
    fi
    if [[ -z "$t" ]] && command -v timedatectl &> /dev/null; then
        t=$(timedatectl show -p Timezone --value 2> /dev/null)
    fi
    if [[ -z "$t" && -f /etc/timezone ]]; then
        t=$(cat /etc/timezone)
    fi
    echo "$t"
}
  
# This installer only does fresh installs. Upgrading an existing Sentora or
# ZPanel server is not supported, so stop if either is already present.
if [ -e "/etc/sentora" ] || [ -e "/etc/zpanel" ] || [ -e "/var/sentora" ] || [ -e "/var/zpanel" ]; then
    echo "It appears that Sentora or ZPanel is already installed on this server."
    echo "This installer is designed to install Sentora on a clean OS installation only,"
    echo "it does not upgrade existing installs."
    echo -e "\nPlease re-install your OS before attempting to install using this script."
    exit 1
fi

# *************************************************
#--- Prepare or query informations required to install

# Update repositories and Install wget and util used to grab server IP
echo -e "\n-- Installing wget and dns utils required to manage inputs"
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
    apt-get -yqq update   #ensure we can install
    if [[ "$VER" = "26.04" ]]; then
        install_available bind9-dnsutils
    else
        $PACKAGE_INSTALLER dnsutils
    fi
fi
$PACKAGE_INSTALLER wget unzip

# Valid dotted IPv4 address?
is_ipv4() {
    [[ "$1" =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]] || return 1
    local o
    for o in "${BASH_REMATCH[@]:1}"; do (( o <= 255 )) || return 1; done
}

# Public IP as seen from the internet. api.sentora.org/ip.txt (used before) no
# longer answers, which left the IP empty and broke BIND's config. Try several
# services and fall back to the local address when none answer.
detect_public_ip() {
    local svc ip
    for svc in https://api.ipify.org https://ipv4.icanhazip.com https://checkip.amazonaws.com https://ifconfig.me/ip; do
        ip=$(wget -4 -qO- --timeout=5 --tries=1 "$svc" 2> /dev/null | tr -d '[:space:]')
        if is_ipv4 "$ip"; then
            echo "$ip"
            return
        fi
    done
}

# First IPv4 address on a real interface (the old command printed one per interface)
local_ip=$(ip -4 addr show scope global | awk '$1 == "inet" { sub(/\/.*/, "", $2); print $2; exit }')
extern_ip="$(detect_public_ip)"
[[ -z "$extern_ip" ]] && extern_ip="$local_ip"

# Enable parameters to be entered on commandline, required for vagrant install
#   -d <panel-domain>
#   -i <server-ip> (or -i local or -i public, see below)
#   -t <timezone-string>
# like :
#   sentora_install.sh -t Europe/Paris -d panel.domain.tld -i xxx.xxx.xxx.xxx
# notes:
#   -d and -i must be both present or both absent
#   -i local  force use of local detected ip
#   -i public  force use of public detected ip
#   if -t is used without -d/-i, timezone is set from value given and not asked to user
#   if -t absent and -d/-i are present, timezone is not set at all

while getopts d:i:t: opt; do
  case $opt in
  d)
      PANEL_FQDN=$OPTARG
      INSTALL="auto"
      ;;
  i)
      PUBLIC_IP=$OPTARG
      if [[ "$PUBLIC_IP" == "local" ]] ; then
          PUBLIC_IP=$local_ip
      elif [[ "$PUBLIC_IP" == "public" ]] ; then
          PUBLIC_IP=$extern_ip
      fi
      ;;
  t)
      tz="$OPTARG"
      if command -v timedatectl &> /dev/null; then
          timedatectl set-timezone "$tz" &> /dev/null
      fi
      echo "$tz" > /etc/timezone
      ;;
  *)
      echo "Usage: $0 [-t <timezone>] [-d <panel domain> -i <ip, local or public>]"
      echo "  e.g. $0 -t Europe/Paris -d panel.domain.tld -i public"
      exit 2
      ;;
  esac
done
if [[ ("$PANEL_FQDN" != "" && "$PUBLIC_IP" == "") || 
      ("$PANEL_FQDN" == "" && "$PUBLIC_IP" != "") ]] ; then
    echo "-d and -i must be both present or both absent."
    exit 2
fi
if [[ -n "$PUBLIC_IP" ]] && ! is_ipv4 "$PUBLIC_IP"; then
    echo "-i must be an IPv4 address, 'local' or 'public' (got: '$PUBLIC_IP')."
    exit 2
fi

#--- Download and unpack Sentora before asking anything
# Download $1 from the internal server to $2, retrying a few times.
download_file() {
    local url="$SENTORA_DOWNLOAD_URL/$1" dest="$2" try
    for try in 1 2 3; do
        echo "Downloading $url (attempt $try)"
        if wget -nv -O "$dest" "$url" && [ -s "$dest" ] && { unzip -tq "$dest" > /dev/null 2>&1 || tar -tzf "$dest" > /dev/null 2>&1; }; then
            return 0
        fi
        echo "Download of $url failed or the file is not a valid zip or tar.gz."
        sleep 5
    done
    return 1
}

# Unpack a downloaded zip into $dest and set STAGED_DIR to the folder that contains $marker.
stage_zip() {
    local zip="$1" marker="$2" dest="$3" top=""
    STAGED_DIR=""
    mkdir -p "$dest"
    if ! unzip -oq "$zip" -d "$dest"; then
        echo "Install failed: could not unzip $zip"
        return 1
    fi
    top=$(find "$dest" -mindepth 1 -maxdepth 2 -type d -name "$marker" -printf '%h\n' 2> /dev/null | head -1)
    if [[ -z "$top" ]]; then
        echo "Install failed: $zip does not contain $marker/."
        return 1
    fi
    STAGED_DIR=$(cd "$top" && pwd)
}

SRC_STAGE=$(mktemp -d /tmp/sentora-src.XXXXXX)

if ! download_file "$SENTORA_CORE_ZIP" "$SRC_STAGE/$SENTORA_CORE_ZIP" \
   || ! stage_zip "$SRC_STAGE/$SENTORA_CORE_ZIP" "dryden" "$SRC_STAGE/core" \
   || [ ! -f "$STAGED_DIR/index.php" ]; then
    echo "Install failed: could not get the Sentora core from $SENTORA_DOWNLOAD_URL/$SENTORA_CORE_ZIP"
    echo "Check that hembackup1.hemsaw.local resolves from this server and the file is there."
    rm -rf "$SRC_STAGE"
    exit 3
fi
CORE_DIR="$STAGED_DIR"

if ! download_file "$SENTORA_INSTALLERS_ZIP" "$SRC_STAGE/$SENTORA_INSTALLERS_ZIP" \
   || ! stage_zip "$SRC_STAGE/$SENTORA_INSTALLERS_ZIP" "preconf" "$SRC_STAGE/preconf"; then
    echo "Install failed: could not get the Sentora configuration files from $SENTORA_DOWNLOAD_URL/$SENTORA_INSTALLERS_ZIP"
    echo "Check that hembackup1.hemsaw.local resolves from this server and the file is there."
    rm -rf "$SRC_STAGE"
    exit 3
fi
PRECONF_DIR="$STAGED_DIR"

if [[ "$tz" == "" && "$PANEL_FQDN" == "" ]] ; then
    # Propose selection list for the time zone
    echo "Preparing to select timezone, please wait a few seconds..."
    $PACKAGE_INSTALLER tzdata
    # setup server timezone
    if [[ "$OS" = "CentOs" ]]; then
        # make tzselect to save TZ in /etc/timezone
        echo "echo \$TZ > /etc/timezone" >> /usr/bin/tzselect
        tzselect
        tz=$(cat /etc/timezone)
    elif [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
        dpkg-reconfigure tzdata
        tz=$(get_timezone)
    fi
fi
# clear timezone information to focus user on important notice
clear

# Installer parameters
if [[ "$PANEL_FQDN" == "" ]] ; then
    echo -e "\n\e[1;33m=== Informations required to build your server ===\e[0m"
    echo 'The installer requires 2 pieces of information:'
    echo ' 1) the sub-domain that you want to use to access Sentora panel,'
    echo '   - do not use your main domain (like domain.com)'
    echo '   - use a sub-domain, e.g panel.domain.com'
    echo '   - or use the server hostname, e.g server1.domain.com'
    echo '   - DNS must already be configured and pointing to the server IP'
    echo '       for this sub-domain'
    echo ' 2) The public IP of the server.'
    echo ''

    PANEL_FQDN="$(/bin/hostname)"
    PUBLIC_IP=$extern_ip
    while true; do
        echo ""
        read -r -e -p "Enter the sub-domain you want to access Sentora panel: " -i "$PANEL_FQDN" PANEL_FQDN

        if [[ "$PUBLIC_IP" != "$local_ip" ]]; then
          echo -e "\nThe public IP of the server is $PUBLIC_IP. Its local IP is $local_ip"
          echo "  For a production server, the PUBLIC IP must be used."
        fi  
        read -r -e -p "Enter (or confirm) the public IP for this server: " -i "$PUBLIC_IP" PUBLIC_IP
        echo ""
        # An empty or malformed IP breaks BIND, Postfix and the certificate: ask again
        if ! is_ipv4 "$PUBLIC_IP"; then
            echo -e "\e[1;31m'$PUBLIC_IP' is not a valid IPv4 address, please enter it again.\e[0m"
            PUBLIC_IP=${extern_ip:-$local_ip}
            continue
        fi

        # Checks if the panel domain is a subdomain
        sub=$(echo "$PANEL_FQDN" | sed -n 's|\(.*\)\..*\..*|\1|p')
        if [[ "$sub" == "" ]]; then
            echo -e "\e[1;31mWARNING: $PANEL_FQDN is not a subdomain!\e[0m"
            confirm="true"
        fi

        # Checks if the panel domain is already assigned in DNS
		
        # Ask DNS directly (dig skips /etc/hosts, where the installer's own entries live).
        # This used api.sentora.org/hostname.txt, which no longer answers.
        if command -v dig &> /dev/null; then
            dns_panel_ip=$(dig +short A "$PANEL_FQDN" 2> /dev/null | grep -E '^[0-9.]+$' | tail -1)
        else
            dns_panel_ip=$(getent ahostsv4 "$PANEL_FQDN" | awk 'NR == 1 {print $1}')
        fi
		
        if [[ "$dns_panel_ip" == "" ]]; then
            echo -e "\e[1;31mWARNING: $PANEL_FQDN is not defined in your DNS!\e[0m"
            echo "  You must add records in your DNS manager (and then wait until propagation is done)."
            echo "  For more information, read the Sentora documentation:"
            echo "   - http://docs.sentora.org/index.php?node=7 (Installing Sentora)"
            echo "   - http://docs.sentora.org/index.php?node=51 (Installer questions)"
            echo "  If this is a production installation, set the DNS up as soon as possible."
            confirm="true"
        else
            echo -e "\e[1;32mOK\e[0m: DNS successfully resolves $PANEL_FQDN to $dns_panel_ip"

            # Check if panel domain matches public IP
            if [[ "$dns_panel_ip" != "$PUBLIC_IP" ]]; then
                echo -e -n "\e[1;31mWARNING: $PANEL_FQDN DNS record does not point to $PUBLIC_IP!\e[0m"
                echo "  Sentora will not be reachable from https://$PANEL_FQDN"
            fi
        fi

                confirm="true"
        if [[ "$PUBLIC_IP" != "$extern_ip" && "$PUBLIC_IP" != "$local_ip" ]]; then
            echo -e -n "\e[1;31mWARNING: $PUBLIC_IP does not match detected IP !\e[0m"
            echo "  Sentora will not work with this IP..."
                confirm="true"
        fi
      
        echo ""
        # if any warning, ask confirmation to continue or propose to change
        if [[ "$confirm" != "" ]] ; then
            echo "There are some warnings..."
            echo "Are you really sure that you want to setup Sentora with these parameters?"
            read -r -e -p "(y):Accept and install, (n):Change domain or IP, (q):Quit installer? " yn
            case $yn in
                [Yy]* ) break;;
                [Nn]* ) continue;;
                [Qq]* ) exit;;
            esac
        else
            read -r -e -p "All is ok. Do you want to install Sentora now (y/n)? " yn
            case $yn in
                [Yy]* ) break;;
                [Nn]* ) exit;;
            esac
        fi
    done
fi

# ***************************************
# Installation really starts here

echo -e "\n# -------------------------------------------------------------------------------"

#--- Setup Sentora Admin contact info

echo -e "\n--- Please Enter vaild contact info for the Sentora system admin or owner below:\n"

# Get Admin contact info 
# ---- Name
while true
do
    read -r -e -p "Enter Full name: " -i "$ADMIN_NAME" ADMIN_NAME
    echo
    if [ -n "$ADMIN_NAME" ]
    then
        break
    else
        echo "Entry is Blank. Try again."
    fi
done

# --- Email
while true
do
    read -r -e -p "Enter admin email: " -i "$ADMIN_EMAIL" ADMIN_EMAIL
    echo
    if [[ "$ADMIN_EMAIL" =~ ^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,4}$ ]]
    then
        break
    else
        echo "Email address $ADMIN_EMAIL is invalid."
    fi
done

# ---- Phone Number
while true
do
    read -r -e -p "Enter Phone Number: " -i "$ADMIN_PHONE" ADMIN_PHONE
    echo
    if [ -n "$ADMIN_PHONE" ]
    then
        break
    else
        echo "Entry is Blank. Try again."
    fi
done

# ---- Address
while true
do
    read -r -e -p "Enter Street Address: " -i "$ADMIN_ADDRESS" ADMIN_ADDRESS
    echo
    if [ -n "$ADMIN_ADDRESS" ]
    then
        break
    else
        echo "Entry is Blank. Try again."
    fi
done

# ---- Address - City, State or Province
while true
do
    read -r -e -p "Enter City, State or Province: " -i "$ADMIN_PROVINCE" ADMIN_PROVINCE
    echo
    if [ -n "$ADMIN_PROVINCE" ]
    then
        break
    else
        echo "Entry is Blank. Try again."
    fi
done

# ---- Address - Postal code
while true
do
    read -r -e -p "Enter Postal code: " -i "$ADMIN_POSTALCODE" ADMIN_POSTALCODE
    echo
    if [ -n "$ADMIN_POSTALCODE" ]
    then
        break
    else
        echo "Entry is Blank. Try again."
    fi
done

# ---- Address - Country
while true
do
    read -r -e -p "Enter Country: " -i "$ADMIN_COUNTRY" ADMIN_COUNTRY
    echo
    if [ -n "$ADMIN_COUNTRY" ]
    then
        break
    else
        echo "Entry is Blank. Try again."
    fi
done

echo -e "\n# -------------------------------------------------------------------------------\n"

#--- Set custom logging methods so we create a log file in the current working directory.
logfile=$(date +%Y-%m-%d_%H.%M.%S_sentora_install.log)
touch "$logfile"
exec > >(tee "$logfile")
exec 2>&1

echo "Installer version $SENTORA_INSTALLER_VERSION"
echo "Sentora core version $SENTORA_CORE_VERSION"
echo ""
echo "Installing Sentora $SENTORA_CORE_VERSION at https://$PANEL_FQDN and ip $PUBLIC_IP"
echo "on server under: $OS  $VER  $ARCH"
uname -a

# Function to disable a file by appending its name with _disabled
disable_file() {
    mv "$1" "$1_disabled_by_sentora" &> /dev/null
}

#--- AppArmor must be disabled to avoid problems
if [[ "$OS" = "Ubuntu" && "$VER" = "26.04" ]]; then
    # Ubuntu 26.04 has no /etc/init.d/apparmor and snapd relies on AppArmor,
    # so stop and disable the service and unload the profiles instead of purging it.
    if systemctl cat apparmor.service &> /dev/null; then
        echo -e "\n-- Disabling AppArmor, please wait..."
        systemctl stop apparmor &> /dev/null
        systemctl disable apparmor &> /dev/null
        command -v aa-teardown &> /dev/null && aa-teardown &> /dev/null
        echo -e "AppArmor has been stopped and disabled."
    fi
elif [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
    [ -f /etc/init.d/apparmor ]
    if [ $? = "0" ]; then
        echo -e "\n-- Disabling and removing AppArmor, please wait..."
        /etc/init.d/apparmor stop &> /dev/null
        update-rc.d -f apparmor remove &> /dev/null
        apt-get remove -y --purge apparmor* &> /dev/null
        disable_file /etc/init.d/apparmor &> /dev/null
        echo -e "AppArmor has been removed."
    fi
fi

#--- Adapt repositories and packages sources
echo -e "\n-- Updating repositories and packages sources"
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then 
    # Update the enabled Aptitude repositories
    echo -ne "\nUpdating Aptitude Repos: " >/dev/tty

    mkdir -p "/etc/apt/sources.list.d.save"
    cp -R /etc/apt/sources.list.d/. "/etc/apt/sources.list.d.save" &> /dev/null
    [ -f "/etc/apt/sources.list" ] && cp "/etc/apt/sources.list" "/etc/apt/sources.list.save"

    if [[ "$VER" = "26.04" ]]; then
        # Ubuntu 26.04 uses the deb822 file ubuntu.sources. Keep its mirror and
        # architecture settings and only make sure every component is enabled.
        UBUNTU_SOURCES="/etc/apt/sources.list.d/ubuntu.sources"
        if [ -f "$UBUNTU_SOURCES" ]; then
            sed -i 's|^Components:.*|Components: main restricted universe multiverse|' "$UBUNTU_SOURCES"
        else
            CODENAME=$(. /etc/os-release && echo "$VERSION_CODENAME")
            if [[ "$ARCH" = "x86_64" || "$ARCH" = i?86 ]]; then
                UB_MIRROR="http://archive.ubuntu.com/ubuntu"
                UB_SECURITY="http://security.ubuntu.com/ubuntu"
            else
                UB_MIRROR="http://ports.ubuntu.com/ubuntu-ports"
                UB_SECURITY="http://ports.ubuntu.com/ubuntu-ports"
            fi
            cat > "$UBUNTU_SOURCES" <<EOF
Types: deb
URIs: $UB_MIRROR
Suites: $CODENAME $CODENAME-updates $CODENAME-backports
Components: main restricted universe multiverse
Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg

Types: deb
URIs: $UB_SECURITY
Suites: $CODENAME-security
Components: main restricted universe multiverse
Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg
EOF
        fi
    elif [[ "$VER" = "24.04" ]]; then
        cat > /etc/apt/sources.list <<EOF
#Depots main restricted
deb http://archive.ubuntu.com/ubuntu $(lsb_release -sc) main restricted universe multiverse
deb http://archive.ubuntu.com/ubuntu $(lsb_release -sc)-security main restricted universe multiverse
deb http://archive.ubuntu.com/ubuntu $(lsb_release -sc)-updates main restricted universe multiverse
EOF
    elif [ "$VER" = "12"  ]; then
        cat > /etc/apt/sources.list <<EOF
			deb http://httpredir.debian.org/debian $(lsb_release -sc) main
			deb-src http://httpredir.debian.org/debian $(lsb_release -sc) main
			
			deb http://httpredir.debian.org/debian $(lsb_release -sc)-updates main
			deb-src http://httpredir.debian.org/debian $(lsb_release -sc)-updates main
			
			#deb http://security.debian.org/ $(lsb_release -sc)/updates main
			#deb-src http://security.debian.org/ $(lsb_release -sc)/updates main
			
			deb http://deb.debian.org/debian-security $(lsb_release -sc)-security main
			deb-src http://deb.debian.org/debian-security $(lsb_release -sc)-security main
EOF

    else
        cat > /etc/apt/sources.list <<EOF
#Depots main restricted
deb http://archive.ubuntu.com/ubuntu/ $(lsb_release -sc) main restricted
deb http://security.ubuntu.com/ubuntu $(lsb_release -sc)-security main restricted
deb http://archive.ubuntu.com/ubuntu/ $(lsb_release -sc)-updates main restricted
 
deb-src http://archive.ubuntu.com/ubuntu/ $(lsb_release -sc) main restricted
deb-src http://archive.ubuntu.com/ubuntu/ $(lsb_release -sc)-updates main restricted
deb-src http://security.ubuntu.com/ubuntu $(lsb_release -sc)-security main restricted

#Depots Universe Multiverse 
deb http://archive.ubuntu.com/ubuntu/ $(lsb_release -sc) universe multiverse
deb http://security.ubuntu.com/ubuntu $(lsb_release -sc)-security universe multiverse
deb http://archive.ubuntu.com/ubuntu/ $(lsb_release -sc)-updates universe multiverse

deb-src http://archive.ubuntu.com/ubuntu/ $(lsb_release -sc) universe multiverse
deb-src http://security.ubuntu.com/ubuntu $(lsb_release -sc)-security universe multiverse
deb-src http://archive.ubuntu.com/ubuntu/ $(lsb_release -sc)-updates universe multiverse
EOF
    fi
fi

#--- List all already installed packages (may help to debug)
echo -e "\n-- Listing of all packages installed:"
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
    dpkg --get-selections
fi

#--- Ensures that all packages are up to date
echo -e "\n-- Updating+upgrading system, it may take some time..."
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
    apt-get -yqq update
    apt-get -yqq upgrade
fi

#--- Install utility packages required by the installer and/or Sentora.
echo -e "\n-- Downloading and installing required tools..."
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
    if [[ "$VER" = "26.04" ]]; then
        # e2fslibs no longer exists. sudo is only installed if missing, so the
        # default sudo-rs of Ubuntu 26.04 is left alone.
        command -v sudo &> /dev/null || install_available sudo
        install_available vim make zip unzip debconf-utils at build-essential bash-completion ca-certificates e2fsprogs
    else
        $PACKAGE_INSTALLER sudo vim make zip unzip debconf-utils at build-essential bash-completion ca-certificates e2fslibs
    fi
fi

###
# Sentora Core Install (downloaded and unpacked at the start)
###
echo -e "\n-- Installing Sentora core from $SENTORA_DOWNLOAD_URL/$SENTORA_CORE_ZIP"
mkdir -p $PANEL_PATH
mkdir -p $PANEL_PATH/panel
mkdir -p $PANEL_DATA
chown -R root:root $PANEL_PATH


## Copy the core into place
# -m: use the install time, so clock differences with the download server do not cause warnings
tar -C "$CORE_DIR" -cf - . | tar -C "$PANEL_PATH/panel" -xmf -

# Remove PHPUnit module test files and files not needed on a server
rm -rf $PANEL_PATH/panel/modules/*/tests/
rm -f "$PANEL_PATH/panel/composer.json" "$PANEL_PATH/panel/composer.lock"
rm -f "$PANEL_PATH/panel/LICENSE.md" "$PANEL_PATH/panel/README.md" "$PANEL_PATH/panel/.gitignore"
rm -rf "$PANEL_PATH/_delete_me" "$PANEL_PATH/.gitignore"

#--- Set-up Sentora directories and configure permissions
PANEL_CONF="$PANEL_PATH/configs"

mkdir -p $PANEL_CONF
mkdir -p $PANEL_PATH/docs
mkdir -p $PANEL_DATA/backups

# Apache writes the panel and per-domain logs here. The ProFTPD and BIND
# sections used to create this folder as a side effect, so create it explicitly.
mkdir -p $PANEL_DATA/logs/domains
chmod 755 $PANEL_DATA/logs $PANEL_DATA/logs/domains

chmod -R 777 $PANEL_PATH/
chmod -R 777 $PANEL_DATA/

# Links for compatibility with zpanel access
ln -s $PANEL_PATH /etc/zpanel
ln -s $PANEL_DATA /var/zpanel

#--- Prepare Sentora executables
chmod +x $PANEL_PATH/panel/bin/zppy 
ln -s $PANEL_PATH/panel/bin/zppy /usr/bin/zppy

chmod +x $PANEL_PATH/panel/bin/setso
ln -s $PANEL_PATH/panel/bin/setso /usr/bin/setso

chmod +x $PANEL_PATH/panel/bin/setzadmin
ln -s $PANEL_PATH/panel/bin/setzadmin /usr/bin/setzadmin

#
#--- Install Sentora preconfig (downloaded and unpacked at the start)
#
echo -e "\n-- Installing Sentora configuration files from $SENTORA_DOWNLOAD_URL/$SENTORA_INSTALLERS_ZIP"
/bin/cp -rf "$PRECONF_DIR/preconf/." $PANEL_CONF

# The staged copies are no longer needed
rm -rf "$SRC_STAGE"

#--- Prepare zsudo (the panel and daemon use it to reload Apache and run root tasks)
if cc -o $PANEL_PATH/panel/bin/zsudo $PANEL_CONF/bin/zsudo.c; then
    chown root $PANEL_PATH/panel/bin/zsudo
    chmod +s $PANEL_PATH/panel/bin/zsudo
else
    echo -e "\n\e[1;31mERROR: zsudo did not compile. Sentora will not be able to reload Apache.\e[0m"
    echo "Check $PANEL_CONF/bin/zsudo.c and build-essential, then run:"
    echo "  cc -o $PANEL_PATH/panel/bin/zsudo $PANEL_CONF/bin/zsudo.c && chmod +s $PANEL_PATH/panel/bin/zsudo"
fi

#--- Resolv.conf protect
# Skipped when it is a symlink (systemd-resolved on Ubuntu 26.04): chattr does not work on it
[ -L /etc/resolv.conf ] || chattr -f +i /etc/resolv.conf

#--- Prepare hostname
old_hostname=$(cat /etc/hostname)
# In file hostname
echo "$PANEL_FQDN" > /etc/hostname

# In file hosts
sed -i "/127.0.1.1[\t ]*$old_hostname/d" /etc/hosts
sed -i "s|$old_hostname|$PANEL_FQDN|" /etc/hosts

# For current session
hostname "$PANEL_FQDN"

#--- Some functions used many times below
# Random password generator function
passwordgen() {
    l=$1
    [ "$l" == "" ] && l=16
    tr -dc A-Za-z0-9 < /dev/urandom | head -c "${l}" | xargs
}

# Add first parameter in hosts file as local IP domain
add_local_domain() {
    if ! grep -q "127.0.0.1 $1" /etc/hosts; then
        echo "127.0.0.1 $1" >> /etc/hosts;
    fi
}
#-----------------------------------------------------------
# Install all softwares and dependencies required by Sentora.

if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
    # Disable the DPKG prompts before we run the software install to enable fully automated install.
    export DEBIAN_FRONTEND=noninteractive
fi

##
#--- MySQL
##
echo -e "\n-- Installing MySQL"
$PACKAGE_INSTALLER "$DB_PCKG" ######## This isnt right
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
    if [[ "$VER" = "26.04" ]]; then
        # /etc/mysql/my.cnf has no [mysqld] section on Ubuntu, the server settings live here
        MY_CNF_PATH="/etc/mysql/mysql.conf.d/mysqld.cnf"
    else
        MY_CNF_PATH="/etc/mysql/my.cnf"
    fi
    DB_SERVICE="mysql"
fi

systemctl start "$DB_SERVICE"

# setup mysql root password only if mysqlpassword is empty
if [ -z "$mysqlpassword" ]; then
    mysqlpassword=$(passwordgen);
	if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
		if [[ "$VER" = "24.04" ]]; then
			# Mysql 8+
			mysql -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '$mysqlpassword';";
		elif [[ "$VER" = "26.04" ]]; then
			# MySQL 8.4+ disables mysql_native_password by default (MySQL 9 removes it).
			# Use the default caching_sha2_password plugin, PHP mysqlnd supports it.
			mysql -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY '$mysqlpassword';";
		elif [[ "$VER" = "12" ]]; then
			# Debian Maria DB 10+
			mysql -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$mysqlpassword';";
		fi
	fi
fi

# small cleaning of mysql access
mysql -u root -p"$mysqlpassword" -e "DELETE FROM mysql.user WHERE User='root' AND Host != 'localhost'";
mysql -u root -p"$mysqlpassword" -e "DELETE FROM mysql.user WHERE User=''";
mysql -u root -p"$mysqlpassword" -e "FLUSH PRIVILEGES";

# remove test table that is no longer used
mysql -u root -p"$mysqlpassword" -e "DROP DATABASE IF EXISTS test";

# secure SELECT "hacker-code" INTO OUTFILE 
sed -i "s|\[mysqld\]|&\nsecure-file-priv = /var/tmp|" $MY_CNF_PATH

# setup sentora access and core database
sed -i "s|YOUR_ROOT_MYSQL_PASSWORD|$mysqlpassword|" $PANEL_PATH/panel/cnf/db.php
mysql -u root -p"$mysqlpassword" < $PANEL_CONF/sentora-install/sql/sentora_core.sql
# Snuffleupagus/Suhosin is not used, remove its settings from the panel
mysql -u root -p"$mysqlpassword" -e "DELETE FROM sentora_core.x_settings WHERE so_name_vc IN ('use_suhosin','suhosin_value')"
# Register mysql/mariadb service for autostart
if [[ "$OS" = "CentOs" ]]; then
    if [[ "$VER" == "7" || "$VER" == "8" ]]; then
        systemctl enable "$DB_SERVICE".service
    else
        #chkconfig "$DB_SERVICE" on
		systemctl enable "$DB_SERVICE"
    fi
fi

# NEED TO FIX UBUNTU 24.04 & Debian 12 SETTING MYSQL-BIND option TO SERVER IP (127.0.0.1) NOT LOCALHOST
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
	sed -i "s|^bind-address[[:space:]]*=.*|bind-address = 127.0.0.1|" /etc/mysql/mysql.conf.d/mysqld.cnf
fi

##
#--- TLS certificate (self-signed for now, see $PANEL_CONF/ssl/README to replace it)
##
echo -e "\n-- Creating a self-signed TLS certificate"
SSL_DIR="$PANEL_CONF/ssl"
mkdir -p "$SSL_DIR"
SSL_SAN="DNS:$PANEL_FQDN"
[[ "$PUBLIC_IP" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] && SSL_SAN="$SSL_SAN,IP:$PUBLIC_IP"
if [ ! -s "$SSL_DIR/sentora.crt" ]; then
    openssl req -x509 -newkey rsa:3072 -sha256 -days 3650 -nodes \
        -keyout "$SSL_DIR/sentora.key" -out "$SSL_DIR/sentora.crt" \
        -subj "/CN=$PANEL_FQDN" -addext "subjectAltName=$SSL_SAN" > /dev/null 2>&1
fi
# ProFTPD takes intermediate certificates from a separate file. A self-signed
# certificate has none, so it starts as a copy; Sencrypt replaces all three files.
[ -s "$SSL_DIR/sentora-chain.crt" ] || cp "$SSL_DIR/sentora.crt" "$SSL_DIR/sentora-chain.crt"
chown root:root "$SSL_DIR/sentora.key" "$SSL_DIR/sentora.crt" "$SSL_DIR/sentora-chain.crt"
chmod 600 "$SSL_DIR/sentora.key"
chmod 644 "$SSL_DIR/sentora.crt" "$SSL_DIR/sentora-chain.crt"

# Serve the panel (and webmail) over HTTPS; the daemon adds an http:// redirect
mysql -u root -p"$mysqlpassword" -e "UPDATE sentora_core.x_settings SET so_value_tx='443' WHERE so_name_vc='sentora_port'"
mysql -u root -p"$mysqlpassword" -e "UPDATE sentora_core.x_settings SET so_value_tx='SSLEngine On\nSSLCertificateFile $SSL_DIR/sentora.crt\nSSLCertificateKeyFile $SSL_DIR/sentora.key' WHERE so_name_vc='panel_ssl_tx'"

##
#--- Postfix
##
echo -e "\n-- Installing Postfix"
# Give root a mail alias before Postfix is installed, so its setup does not warn
# "/etc/aliases exists, but does not have a root alias"
grep -q "^postmaster:" /etc/aliases 2> /dev/null || echo "postmaster: root" >> /etc/aliases
grep -q "^root:" /etc/aliases 2> /dev/null || echo "root: $ADMIN_EMAIL" >> /etc/aliases
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
    $PACKAGE_INSTALLER postfix postfix-mysql
    USR_LIB_PATH="/usr/lib"
fi

postfixpassword=$(passwordgen);
mysql -u root -p"$mysqlpassword" < $PANEL_CONF/sentora-install/sql/sentora_postfix.sql

# Add User for Postfix DB (MySQL 8.4 default plugin caching_sha2_password)
mysql -u root -p"$mysqlpassword" -e "CREATE USER postfix@localhost IDENTIFIED BY '$postfixpassword';";
mysql -u root -p"$mysqlpassword" -e "GRANT ALL PRIVILEGES ON sentora_postfix .* TO 'postfix'@'localhost';";

mkdir -p $PANEL_DATA/vmail
useradd -r -g mail -d $PANEL_DATA/vmail -s /sbin/nologin -c "Virtual maildir" vmail
chown -R vmail:mail $PANEL_DATA/vmail
chmod -R 770 $PANEL_DATA/vmail

add_local_domain "$PANEL_FQDN"

rm -rf /etc/postfix/main.cf /etc/postfix/master.cf
ln -s $PANEL_CONF/postfix/master.cf /etc/postfix/master.cf
ln -s $PANEL_CONF/postfix/main.cf /etc/postfix/main.cf

sed -i "s|!POSTFIX_PASSWORD!|$postfixpassword|" $PANEL_CONF/postfix/*.cf
sed -i "s|!PANEL_FQDN!|$PANEL_FQDN|" $PANEL_CONF/postfix/main.cf
sed -i "s|!USR_LIB!|$USR_LIB_PATH|" $PANEL_CONF/postfix/master.cf
sed -i "s|!USR_LIB!|$USR_LIB_PATH|" $PANEL_CONF/postfix/main.cf
sed -i "s|!SERVER_IP!|$PUBLIC_IP|" $PANEL_CONF/postfix/main.cf

VMAIL_UID=$(id -u vmail)
MAIL_GID=$(sed -nr "s/^mail:x:([0-9]+):.*/\1/p" /etc/group)
sed -i "s|!POS_UID!|$VMAIL_UID|g" $PANEL_CONF/postfix/main.cf
sed -i "s|!POS_GID!|$MAIL_GID|g" $PANEL_CONF/postfix/main.cf

# remove unused directives that issue warnings
sed -i '/virtual_mailbox_limit_maps/d' $PANEL_CONF/postfix/main.cf
sed -i '/smtpd_bind_address/d' $PANEL_CONF/postfix/master.cf

# Debian/Ubuntu keep the Postfix daemons in /usr/lib/postfix/sbin
sed -i "s|daemon_directory = /usr/lib/postfix$|daemon_directory = /usr/lib/postfix/sbin|" $PANEL_CONF/postfix/main.cf
sed -i "s|daemon_directory = /usr/lib/postfix |daemon_directory = /usr/lib/postfix/sbin |" $PANEL_CONF/postfix/main.cf

# System mail for root (cron, logwatch...) goes to the admin email entered above
grep -q "^root:" /etc/aliases 2> /dev/null || echo "root: $ADMIN_EMAIL" >> /etc/aliases
newaliases
systemctl enable postfix

##
#--- Dovecot 2.4 (includes Sieve)
##
echo -e "\n-- Installing Dovecot"
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
    install_available dovecot-core dovecot-mysql dovecot-imapd dovecot-pop3d dovecot-lmtpd dovecot-managesieved dovecot-sieve
    sed -i "s|#first_valid_uid = ?|first_valid_uid = $VMAIL_UID\nlast_valid_uid = $VMAIL_UID\n\nfirst_valid_gid = $MAIL_GID\nlast_valid_gid = $MAIL_GID|" $PANEL_CONF/dovecot2/dovecot.conf
fi

mkdir -p $PANEL_DATA/sieve
chown -R vmail:mail $PANEL_DATA/sieve
ln -sf $PANEL_CONF/dovecot2/globalfilter.sieve $PANEL_DATA/sieve/globalfilter.sieve

rm -f /etc/dovecot/dovecot.conf
ln -s $PANEL_CONF/dovecot2/dovecot.conf /etc/dovecot/dovecot.conf
sed -i "s|!POSTMASTER_EMAIL!|postmaster@$PANEL_FQDN|" $PANEL_CONF/dovecot2/dovecot.conf
sed -i "s|!POSTFIX_PASSWORD!|$postfixpassword|" $PANEL_CONF/dovecot2/dovecot-mysql.conf
sed -i "s|!DOV_UID!|$VMAIL_UID|g" $PANEL_CONF/dovecot2/dovecot-mysql.conf
sed -i "s|!DOV_GID!|$MAIL_GID|g" $PANEL_CONF/dovecot2/dovecot-mysql.conf
# The SQL file holds the postfix password: root and the mail group only
# (dovecot-lda runs as vmail:mail when Postfix delivers and must read it)
chown root:mail $PANEL_CONF/dovecot2/dovecot-mysql.conf
chmod 640 $PANEL_CONF/dovecot2/dovecot-mysql.conf

touch /var/log/dovecot.log /var/log/dovecot-info.log /var/log/dovecot-debug.log
chown vmail:mail /var/log/dovecot*
chmod 660 /var/log/dovecot*

if doveconf -n > /dev/null; then
    systemctl enable dovecot
    systemctl restart dovecot
else
    echo -e "\n\e[1;31mWARNING: the Dovecot configuration did not pass doveconf, Dovecot was not started.\e[0m"
fi
systemctl restart postfix

##
#--- Apache server
##
echo -e "\n-- Installing and configuring Apache"
$PACKAGE_INSTALLER "$HTTP_PCKG"
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
    install_available libapache2-mod-bw
    HTTP_CONF_PATH="/etc/apache2/apache2.conf"
    HTTP_VARS_PATH="/etc/apache2/envvars"
    HTTP_SERVICE="apache2"
    HTTP_USER="www-data"
    HTTP_GROUP="www-data"
    a2enmod rewrite
fi

if ! grep -q "Include $PANEL_CONF/apache/httpd.conf" "$HTTP_CONF_PATH"; then
    echo "Include $PANEL_CONF/apache/httpd.conf" >> "$HTTP_CONF_PATH";
fi
add_local_domain "$(hostname)"

if ! grep -q "apache ALL=NOPASSWD: $PANEL_PATH/panel/bin/zsudo" /etc/sudoers; then
    echo "apache ALL=NOPASSWD: $PANEL_PATH/panel/bin/zsudo" >> /etc/sudoers;
fi

# Create root directory for public HTTP docs
mkdir -p $PANEL_DATA/hostdata/zadmin/public_html
chown -R $HTTP_USER:$HTTP_GROUP $PANEL_DATA/hostdata/
chmod -R 770 $PANEL_DATA/hostdata/

mysql -u root -p"$mysqlpassword" -e "UPDATE sentora_core.x_settings SET so_value_tx='$HTTP_SERVICE' WHERE so_name_vc='httpd_exe'"
mysql -u root -p"$mysqlpassword" -e "UPDATE sentora_core.x_settings SET so_value_tx='$HTTP_SERVICE' WHERE so_name_vc='apache_sn'"

#Set keepalive on (default is off)
sed -i "s|KeepAlive Off|KeepAlive On|" "$HTTP_CONF_PATH"

# Group-writable files for Apache
if ! grep -q "umask 002" "$HTTP_VARS_PATH"; then
    echo "umask 002" >> "$HTTP_VARS_PATH";
fi

# remove default virtual site to ensure Sentora is the default vhost
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
    # disable completely sites-enabled/000-default.conf
    if [[ "$VER" = "24.04" || "$VER" = "26.04" || "$VER" = "12" ]]; then
        sed -i "s|IncludeOptional sites-enabled|#&|" "$HTTP_CONF_PATH"
    else
        sed -i "s|Include sites-enabled|#&|" "$HTTP_CONF_PATH"
    fi
fi

# Comment "NameVirtualHost" and Listen directives that are handled in vhosts file
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
    sed -i "s|\(Include ports.conf\)|#\1\n# Ports are now handled in Sentora vhosts file|" "$HTTP_CONF_PATH"
    disable_file /etc/apache2/ports.conf
fi

# adjustments for apache 2.4
if [[ ("$OS" = "Ubuntu" && ( "$VER" = "24.04" || "$VER" = "26.04" )) || 
      ("$OS" = "debian" && "$VER" = "12") ]] ; then 
    # Order deny,allow / Deny from all   ->  Require all denied
    sed -i 's|Order deny,allow|Require all denied|I'  $PANEL_CONF/apache/httpd.conf
    sed -i '/Deny from all/d' $PANEL_CONF/apache/httpd.conf

    # Order allow,deny / Allow from all  ->  Require all granted
    sed -i 's|Order allow,deny|Require all granted|I' $PANEL_CONF/apache/httpd-vhosts.conf
    sed -i '/Allow from all/d' $PANEL_CONF/apache/httpd-vhosts.conf

    sed -i 's|Order allow,deny|Require all granted|I'  $PANEL_PATH/panel/modules/apache_admin/hooks/OnDaemonRun.hook.php
    sed -i '/Allow from all/d' $PANEL_PATH/panel/modules/apache_admin/hooks/OnDaemonRun.hook.php

    # Remove NameVirtualHost that is now without effect and generate warning.
    # Only that line: the old '{N;d}' also deleted the "Listen 80" line after it,
    # so Apache had no port to listen on until the daemon rewrote the file.
    sed -i '/^NameVirtualHost/d' $PANEL_CONF/apache/httpd-vhosts.conf
    sed -i '/# NameVirtualHost is/ {N;N;N;N;N;d}' $PANEL_PATH/panel/modules/apache_admin/hooks/OnDaemonRun.hook.php

    # Options must have ALL (or none) +/- prefix, disable listing directories
    sed -i 's| FollowSymLinks [-]Indexes| +FollowSymLinks -Indexes|' $PANEL_PATH/panel/modules/apache_admin/hooks/OnDaemonRun.hook.php
fi

#--- Apache+Mod_SSL
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
	if [[ "$VER" = "24.04" || "$VER" = "26.04" || "$VER" = "12" ]]; then
		# Install Mod_ssl & openssl
		#$PACKAGE_INSTALLER mod_ssl
		$PACKAGE_INSTALLER openssl
		
		# Activate mod_ssl
		a2enmod ssl 
	fi
	
fi 

#############################
##
#--- PHP Install Starts Here
##
echo -e "\n-Installing OS Default PHP version..."

# Install OS Default PHP version

if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
	if [[ "$VER" = "24.04" || "$VER" = "26.04" || "$VER" = "12" ]]; then	
	
		install_available libapache2-mod-php php-common php-bcmath php-cli php-mysql php-gd php-curl php-pear php-imagick php-xmlrpc php-xml php-xsl php-intl php-mbstring php-dev php-zip
		
		# Prepare PHP-mcrypt files
		$PACKAGE_INSTALLER -y build-essential
		
		# Download needed files
		install_available libmcrypt-dev
	fi
fi

##
echo -e "\n-- Configuring PHP..."
##

# PHP version check (PHP 8.X )

###
# Check supported OS default PHP 8.X installed before continuing
###

if [[ "$VER" = "24.04" || "$VER" = "26.04" || "$VER" = "12" ]]; then

	##### Check PHP 8.x was installed or quit installer.
	PHPVER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;') # return 8.x
	
	echo -e "\nDetected PHP: $PHPVER "

	if  [[ "$PHPVER" == 8.* ]]; then
		echo -e "\nPHP $PHPVER installed. Procced installing ..."
	else
		echo -e "\nPHP 8.x not installed. $PHPVER installed. Exiting installer. Please contact your script admin!!"
		exit 1
	fi
fi
	
# Set PHP.ini path**
PHP_INI_PATH="/etc/php/$PHPVER/apache2/php.ini"

# PHP 8.* Extra packages needed by different OS's

if [[ "$OS" = "Ubuntu" && ( "$VER" = "24.04" || "$VER" = "26.04" ) ||
      "$OS" = "debian" && ( "$VER" = "12" ) ]] ; then

	# PHP-mcrypt install code all OS - Check this!!!!!!
			
	# Update Pecl Channels
	echo -e "\n--- Updating PECL Channels..."
	# Only pecl.php.net is needed for mcrypt. "pecl update-channels" also tried
	# doc.php.net, whose channel file no longer parses, and printed PHP warnings.
	pecl channel-update pecl.php.net
	
	if [[ "$VER" = "24.04" || "$VER" = "26.04" || "$VER" = "12" ]]; then
		# Make pear cache folder to stop error "Trying to access array offset on value of type bool in PEAR/REST.php on line 187"
		mkdir -p /tmp/pear/cache
	fi
	
	# Install PHP-Mcrypt
	# libmcrypt is gone from newer releases, only try to build mcrypt when it is there
	if inst libmcrypt-dev; then
		echo -e "\n--- Installing PHP-mcrypt..."
		echo -ne '\n' | pecl install mcrypt
	else
		echo -e "\n--- libmcrypt-dev not available, skipping PHP-mcrypt"
	fi

fi

# Setup PHP mcrypt config files, only if the extension was actually built
PHP_EXT_DIR=$(php -r 'echo ini_get("extension_dir");')
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
	if [ -f "$PHP_EXT_DIR/mcrypt.so" ]; then

		# Create php-mcrypt modules file
		echo 'extension=mcrypt.so' > /etc/php/$PHPVER/mods-available/mcrypt.ini

		# Create links to activate PHP-mcrypt
		ln -sf /etc/php/$PHPVER/mods-available/mcrypt.ini /etc/php/$PHPVER/apache2/conf.d/20-mcrypt.ini
		ln -sf /etc/php/$PHPVER/mods-available/mcrypt.ini /etc/php/$PHPVER/cli/conf.d/20-mcrypt.ini
	else
		echo -e "\nPHP-mcrypt was not built for PHP $PHPVER, it will not be enabled."
	fi
fi

# Set PHP Memory limit
echo -e "\n-- Setting PHP memory limit to 256MB..."
sed -i "s|memory_limit = .*|memory_limit = 256M|g" $PHP_INI_PATH

# Setup php upload dir
mkdir -p $PANEL_DATA/temp
chmod 1777 $PANEL_DATA/temp/
chown -R $HTTP_USER:$HTTP_GROUP $PANEL_DATA/temp/

# Setup php session save directory
mkdir "$PANEL_DATA/sessions"
chown $HTTP_USER:$HTTP_GROUP "$PANEL_DATA/sessions"
chmod 733 "$PANEL_DATA/sessions"
chmod +t "$PANEL_DATA/sessions"

if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
    sed -i "s|;session.save_path = .*|session.save_path = \"$PANEL_DATA/sessions\"|g" $PHP_INI_PATH
fi

sed -i "/php_value/d" $PHP_INI_PATH
echo "session.save_path = $PANEL_DATA/sessions;" >> $PHP_INI_PATH

# setup timezone and upload temp dir
sed -i "s|;date.timezone =|date.timezone = $tz|g" $PHP_INI_PATH
sed -i "s|;upload_tmp_dir =|upload_tmp_dir = $PANEL_DATA/temp/|g" $PHP_INI_PATH

# Disable php signature in headers to hide it from hackers
sed -i 's|expose_php = On|expose_php = Off|g' $PHP_INI_PATH

# Ubuntu 26.04 runs Apache with MemoryDenyWriteExecute, which blocks PCRE's JIT
# compiler and logs a warning in every Apache process. Use the plain matcher.
if grep -q "^;*pcre.jit" $PHP_INI_PATH; then
    sed -i "s|^;*pcre.jit *=.*|pcre.jit = 0|" $PHP_INI_PATH
else
    echo "pcre.jit = 0" >> $PHP_INI_PATH
fi

# Ubuntu 26.04's apache2.service is sandboxed: /etc is read-only to Apache
# (ProtectSystem=full) and most of /proc is hidden (ProcSubset=pid). The panel
# lives in /etc/sentora and writes its template cache and settings there, and
# reads /proc for uptime and system info, so a white page is all you get
# without this override. The other hardening settings are kept.
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
    mkdir -p /etc/systemd/system/apache2.service.d
    cat > /etc/systemd/system/apache2.service.d/sentora.conf <<EOF_UNIT
[Service]
ReadWritePaths=$PANEL_PATH
ProtectProc=default
ProcSubset=all
EOF_UNIT
    systemctl daemon-reload
fi

# Register apache(+php) services for autostart and start it
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
        #chkconfig "$HTTP_SERVICE" on
		systemctl enable "$HTTP_SERVICE"
        systemctl start "$HTTP_SERVICE"
fi

##
#--- Extra PHP versions for hosted domains (PHP-FPM)
##
# The panel and every domain without a choice keep using the system PHP 8.5
# (Apache mod_php). These versions are built for Ubuntu 26.04 and hosted next
# to the other installer files; each runs as its own PHP-FPM service and is
# enabled or disabled in the panel's PHP Versions module.
PHP_EXTRA_VERSIONS="8.1 8.2 8.3 8.4"
echo -e "\n-- Installing extra PHP versions: $PHP_EXTRA_VERSIONS"
# Libraries the builds link against (all part of Ubuntu 26.04)
install_available libxml2-16 libcurl4t64 libonig5 libzip5 libicu78 libsodium23 libargon2-1 \
    libxslt1.1 libwebp7 libjpeg-turbo8 libfreetype6 libpng16-16t64 libgmp10 libsqlite3-0 libssl3t64
a2enmod proxy_fcgi setenvif > /dev/null
PHP_STAGE=$(mktemp -d /tmp/sentora-php.XXXXXX)
PHP_INSTALLED=""
for PV in $PHP_EXTRA_VERSIONS; do
    PHP_TAR="sentora-php-$PV-ubuntu26.04-amd64.tar.gz"
    if ! download_file "$PHP_TAR" "$PHP_STAGE/$PHP_TAR"; then
        echo -e "\e[1;33mPHP $PV skipped: $PHP_TAR is not on $SENTORA_DOWNLOAD_URL\e[0m"
        continue
    fi
    # The archive holds opt/sentora-php/<version>/
    # -m: use the install time, the build machine clock may differ from this server
    tar -xzmf "$PHP_STAGE/$PHP_TAR" -C /
    PP="/opt/sentora-php/$PV"
    if ! "$PP/sbin/php-fpm" -v > /dev/null 2>&1; then
        echo -e "\e[1;31mWARNING: PHP $PV does not run on this server, skipped.\e[0m"
        "$PP/sbin/php-fpm" -v 2>&1 | head -3
        continue
    fi

    # php.ini: PHP's production defaults with the same changes as the system PHP
    mkdir -p "$PP/etc/conf.d" "$PP/etc/php-fpm.d"
    cp "$PP/lib/php.ini-production" "$PP/etc/php.ini"
    sed -i "s|^memory_limit = .*|memory_limit = 256M|" "$PP/etc/php.ini"
    sed -i "s|^expose_php = .*|expose_php = Off|" "$PP/etc/php.ini"
    sed -i "s|^;date.timezone =.*|date.timezone = $tz|" "$PP/etc/php.ini"
    sed -i "s|^;upload_tmp_dir =.*|upload_tmp_dir = $PANEL_DATA/temp/|" "$PP/etc/php.ini"
    # These builds do not know Ubuntu's MySQL socket path, so tell them
    sed -i "s|^mysqli.default_socket =.*|mysqli.default_socket = /var/run/mysqld/mysqld.sock|" "$PP/etc/php.ini"
    sed -i "s|^pdo_mysql.default_socket *=.*|pdo_mysql.default_socket = /var/run/mysqld/mysqld.sock|" "$PP/etc/php.ini"
    echo "zend_extension=opcache.so" > "$PP/etc/conf.d/10-opcache.ini"

    cat > "$PP/etc/php-fpm.conf" <<EOF_FPM
[global]
pid = /run/sentora-php/php$PV-fpm.pid
error_log = $PANEL_DATA/logs/php$PV-fpm.log
include = $PP/etc/php-fpm.d/*.conf
EOF_FPM
    # One pool for all domains on this version. Processes start on demand, so a
    # version nobody uses costs only its small master process. Each request gets
    # the domain's open_basedir, upload and session folders from Apache.
    cat > "$PP/etc/php-fpm.d/www.conf" <<EOF_POOL
[www]
user = $HTTP_USER
group = $HTTP_GROUP
listen = /run/sentora-php/php$PV-fpm.sock
listen.owner = $HTTP_USER
listen.group = $HTTP_GROUP
listen.mode = 0660
pm = ondemand
pm.max_children = 20
pm.process_idle_timeout = 60s
pm.max_requests = 500
security.limit_extensions = .php .phtml .php3
EOF_POOL

    cat > "/etc/systemd/system/sentora-php$PV-fpm.service" <<EOF_UNIT
[Unit]
Description=PHP $PV FastCGI Process Manager for Sentora hosted domains
After=network.target

[Service]
Type=simple
RuntimeDirectory=sentora-php
RuntimeDirectoryPreserve=yes
ExecStartPre=$PP/sbin/php-fpm --test --fpm-config $PP/etc/php-fpm.conf
ExecStart=$PP/sbin/php-fpm --nodaemonize --fpm-config $PP/etc/php-fpm.conf
ExecReload=/bin/kill -USR2 \$MAINPID
Restart=on-failure
PrivateTmp=true

[Install]
WantedBy=multi-user.target
EOF_UNIT
    touch "$PANEL_DATA/logs/php$PV-fpm.log"
    PHP_INSTALLED="$PHP_INSTALLED,$PV"
done
rm -rf "$PHP_STAGE"
systemctl daemon-reload
PHP_INSTALLED="${PHP_INSTALLED#,}"
for PV in ${PHP_INSTALLED//,/ }; do
    systemctl enable --now "sentora-php$PV-fpm" > /dev/null 2>&1 || echo -e "\e[1;31mWARNING: sentora-php$PV-fpm did not start.\e[0m"
done
# All installed versions start enabled; the PHP Versions module can switch them off
mysql -u root -p"$mysqlpassword" -e "UPDATE sentora_core.x_settings SET so_value_tx='$PHP_INSTALLED' WHERE so_name_vc='php_versions_enabled'"
echo "Extra PHP versions installed: ${PHP_INSTALLED:-none}"

##
#--- ProFTPd
##
echo -e "\n-- Installing ProFTPD"
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
    # proftpd-mod-crypto provides mod_tls for FTPS
    $PACKAGE_INSTALLER proftpd-mod-mysql proftpd-mod-crypto
    FTP_CONF_PATH='/etc/proftpd/proftpd.conf'
fi

# Create and init proftpd database
mysql -u root -p"$mysqlpassword" < $PANEL_CONF/sentora-install/sql/sentora_proftpd.sql

# Create and configure mysql password for proftpd
proftpdpassword=$(passwordgen);
sed -i "s|!SQL_PASSWORD!|$proftpdpassword|" $PANEL_CONF/proftpd/proftpd-mysql.conf

# Add User for Proftpd DB (MySQL 8.4 default plugin caching_sha2_password)
mysql -u root -p"$mysqlpassword" -e "CREATE USER proftpd@localhost IDENTIFIED BY '$proftpdpassword';";
# Grant ALL PRIVILEGES to Proftpd User
mysql -u root -p"$mysqlpassword" -e "GRANT ALL PRIVILEGES ON sentora_proftpd .* TO 'proftpd'@'localhost';";

# Assign httpd user and group to all users that will be created
HTTP_UID=$(id -u "$HTTP_USER")
HTTP_GID=$(sed -nr "s/^$HTTP_GROUP:x:([0-9]+):.*/\1/p" /etc/group)
mysql -u root -p"$mysqlpassword" -e "ALTER TABLE sentora_proftpd.ftpuser ALTER COLUMN uid SET DEFAULT $HTTP_UID"
mysql -u root -p"$mysqlpassword" -e "ALTER TABLE sentora_proftpd.ftpuser ALTER COLUMN gid SET DEFAULT $HTTP_GID"
sed -i "s|!SQL_MIN_ID!|$HTTP_UID|" $PANEL_CONF/proftpd/proftpd-mysql.conf

# Setup proftpd base file to call sentora config
rm -f "$FTP_CONF_PATH"
ln -s "$PANEL_CONF/proftpd/proftpd-mysql.conf" "$FTP_CONF_PATH"

# Setup proftpd log dir (the folder needs execute permission to be usable)
mkdir -p $PANEL_DATA/logs/proftpd
chmod 755 $PANEL_DATA/logs/proftpd

# Register proftpd for autostart and (re)start it with the Sentora config
systemctl enable proftpd
systemctl restart proftpd

##
#--- BIND
##
echo -e "\n-- Installing and configuring BIND"
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
    if [[ "$VER" = "26.04" ]]; then
        install_available bind9 bind9-utils
        BIND_SERVICE="named"
    else
        $PACKAGE_INSTALLER bind9 bind9utils
        BIND_SERVICE="bind9"
    fi
    BIND_PATH="/etc/bind/"
    BIND_FILES="/etc/bind"
    BIND_USER="bind"
    mysql -u root -p"$mysqlpassword" -e "UPDATE sentora_core.x_settings SET so_value_tx='/var/sentora/logs/bind/bind.log' WHERE so_name_vc='bind_log'"
fi

mysql -u root -p"$mysqlpassword" -e "UPDATE sentora_core.x_settings SET so_value_tx='$BIND_PATH' WHERE so_name_vc='bind_dir'"
mysql -u root -p"$mysqlpassword" -e "UPDATE sentora_core.x_settings SET so_value_tx='$BIND_SERVICE' WHERE so_name_vc='bind_service'"
chmod -R 777 $PANEL_CONF/bind/zones/

# Setup logging directory
mkdir -p $PANEL_DATA/logs/bind
touch $PANEL_DATA/logs/bind/bind.log $PANEL_DATA/logs/bind/debug.log
chown $BIND_USER $PANEL_DATA/logs/bind/bind.log $PANEL_DATA/logs/bind/debug.log
chmod 660 $PANEL_DATA/logs/bind/bind.log $PANEL_DATA/logs/bind/debug.log

mkdir -p /var/named/dynamic /var/named/data
touch /var/named/dynamic/managed-keys.bind
chown -R bind:bind /var/named/
chmod -R 777 $PANEL_CONF/bind/etc

# The check tools are in /usr/bin on Ubuntu 26.04 and /usr/sbin on older releases,
# Sentora calls them by name, so make sure both locations work
for tool in named-checkconf named-checkzone named-compilezone; do
    [ -e /usr/bin/$tool ] || [ ! -e /usr/sbin/$tool ] || ln -s /usr/sbin/$tool /usr/bin/$tool
done

# Setup acl IP to forbid zone transfer
sed -i "s|!SERVER_IP!|$PUBLIC_IP|" $PANEL_CONF/bind/named.conf

# Build key and conf files
rm -rf $BIND_FILES/named.conf $BIND_FILES/rndc.conf $BIND_FILES/rndc.key
rndc-confgen -a -A hmac-sha256
cat $BIND_FILES/rndc.key $PANEL_CONF/bind/named.conf > $BIND_FILES/named.conf
cat $BIND_FILES/rndc.key $PANEL_CONF/bind/rndc.conf > $BIND_FILES/rndc.conf
rm -f $BIND_FILES/rndc.key

# Ubuntu's AppArmor profile for named only allows /etc/bind, while Sentora keeps
# BIND's config, zones and logs in /etc/sentora, /var/named and /var/sentora, so
# named fails to start while that profile is loaded. Disable this one profile; the
# disable link also stops bind9 package updates from loading it again.
if [ -f /etc/apparmor.d/usr.sbin.named ]; then
    mkdir -p /etc/apparmor.d/disable
    ln -sf /etc/apparmor.d/usr.sbin.named /etc/apparmor.d/disable/usr.sbin.named
    command -v apparmor_parser > /dev/null && apparmor_parser -R /etc/apparmor.d/usr.sbin.named > /dev/null 2>&1
fi

if named-checkconf $BIND_FILES/named.conf; then
    systemctl enable "$BIND_SERVICE"
    systemctl restart "$BIND_SERVICE"
else
    echo -e "\n\e[1;31mWARNING: $BIND_FILES/named.conf did not pass named-checkconf, BIND was not started.\e[0m"
fi

##
#--- CRON and ATD
##
echo -e "\n-- Installing and configuring cron tasks"
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
    $PACKAGE_INSTALLER cron
    CRON_DIR="/var/spool/cron/crontabs"
    CRON_SERVICE="cron"
fi

CRON_USER="$HTTP_USER"

# prepare daemon crontab
# sed -i "s|!USER!|$CRON_USER|" "$PANEL_CONF/cron/zdaemon" #it screw update search!#
sed -i "s|!USER!|root|" "$PANEL_CONF/cron/zdaemon"
cp "$PANEL_CONF/cron/zdaemon" /etc/cron.d/zdaemon
chmod 644 /etc/cron.d/zdaemon

# prepare user crontabs
CRON_FILE="$CRON_DIR/$CRON_USER"
mysql -u root -p"$mysqlpassword" -e "UPDATE sentora_core.x_settings SET so_value_tx='$CRON_FILE' WHERE so_name_vc='cron_file'"
mysql -u root -p"$mysqlpassword" -e "UPDATE sentora_core.x_settings SET so_value_tx='$CRON_FILE' WHERE so_name_vc='cron_reload_path'"
mysql -u root -p"$mysqlpassword" -e "UPDATE sentora_core.x_settings SET so_value_tx='$CRON_USER' WHERE so_name_vc='cron_reload_user'"
{
    echo "SHELL=/bin/bash"
    echo "PATH=/sbin:/bin:/usr/sbin:/usr/bin"
    echo ""
} > mycron
crontab -u $HTTP_USER mycron
rm -f mycron

chmod 744 "$CRON_DIR"
chown -R $HTTP_USER:$HTTP_USER "$CRON_DIR"
chmod 644 "$CRON_FILE"

# Register cron and atd services for autostart and start them
if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
	if [[ "$VER" = "24.04" || "$VER" = "26.04" || "$VER" = "12" ]]; then
        # the service is "cron" on Debian/Ubuntu, not "crond"
		systemctl enable cron atd
        systemctl start cron
        systemctl start atd
    fi
fi

##
#--- phpMyAdmin
##
echo -e "\n-- Configuring phpMyAdmin"
phpmyadminsecret=$(passwordgen 32);
chmod 644 $PANEL_CONF/phpmyadmin/config.inc.php
sed -i "s|\$cfg\['blowfish_secret'\] = 'SENTORA';|\$cfg\['blowfish_secret'\] = '$phpmyadminsecret';|" $PANEL_CONF/phpmyadmin/config.inc.php
ln -sf $PANEL_CONF/phpmyadmin/config.inc.php $PANEL_PATH/panel/etc/apps/phpmyadmin/config.inc.php
# Remove phpMyAdmin's setup folder, it is not needed and should not be reachable
rm -rf $PANEL_PATH/panel/etc/apps/phpmyadmin/setup

##
#--- PHPsysinfo
##
echo -e "\n-- Configuring PHPsysinfo"
# Setup config file
mv -f /etc/sentora/panel/etc/apps/phpsysinfo/phpsysinfo.ini.new /etc/sentora/panel/etc/apps/phpsysinfo/phpsysinfo.ini

##
#--- Roundcube 1.7 (webmail)
##
echo -e "\n-- Configuring Roundcube"
RC_PATH="$PANEL_PATH/panel/etc/apps/webmail"

# Create the database from the schema shipped with this Roundcube version
mysql -u root -p"$mysqlpassword" -e "CREATE DATABASE IF NOT EXISTS sentora_roundcube CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -p"$mysqlpassword" sentora_roundcube < "$RC_PATH/SQL/mysql.initial.sql"

roundcubepassword=$(passwordgen);
mysql -u root -p"$mysqlpassword" -e "CREATE USER roundcube@localhost IDENTIFIED BY '$roundcubepassword';";
mysql -u root -p"$mysqlpassword" -e "GRANT ALL PRIVILEGES ON sentora_roundcube .* TO 'roundcube'@'localhost';";
sed -i "s|!ROUNDCUBE_PASSWORD!|$roundcubepassword|" $PANEL_CONF/roundcube/roundcube_config.inc.php

roundcube_des_key=$(passwordgen 24);
sed -i "s|!ROUNDCUBE_DESKEY!|$roundcube_des_key|" $PANEL_CONF/roundcube/roundcube_config.inc.php

# The password plugin updates mailbox passwords in the Postfix database
sed -i "s|!POSTFIX_PASSWORD!|$postfixpassword|" $PANEL_CONF/roundcube/password_config.inc.php

# The schema is imported, the setup files are not needed on a live server
rm -rf "$RC_PATH/SQL" "$RC_PATH/installer" "$RC_PATH/public_html/installer.php"

# Logs and temporary files live outside the application folder
mkdir -p "$PANEL_DATA/logs/roundcube" "$PANEL_DATA/temp/roundcube"
chown "$HTTP_USER:$HTTP_GROUP" "$PANEL_DATA/logs/roundcube" "$PANEL_DATA/temp/roundcube"

# Config files hold database passwords: readable by root and Apache only
chown root:$HTTP_GROUP $PANEL_CONF/roundcube/*.inc.php
chmod 640 $PANEL_CONF/roundcube/*.inc.php

ln -sf $PANEL_CONF/roundcube/roundcube_config.inc.php "$RC_PATH/config/config.inc.php"
ln -sf $PANEL_CONF/roundcube/sieve_config.inc.php "$RC_PATH/plugins/managesieve/config.inc.php"
ln -sf $PANEL_CONF/roundcube/password_config.inc.php "$RC_PATH/plugins/password/config.inc.php"

##
#--- Webalizer
##
echo -e "\n-- Configuring Webalizer"

if [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
	if [[ -n "$(apt-cache policy webalizer 2> /dev/null | awk '/Candidate:/ && $2 != "(none)" {print $2}')" ]]; then
		$PACKAGE_INSTALLER webalizer
	else
		# Ubuntu 26.04 no longer packages webalizer: build the bundled 2.23-08
		# source (with Ubuntu's patches) into /usr/local/bin/webalizer
		install_available patch libgd-dev libpng-dev zlib1g-dev
		if ! bash $PANEL_CONF/webalizer/build-webalizer.sh $PANEL_CONF/webalizer; then
			echo -e "\n\e[1;31mWARNING: webalizer did not build, Webalizer Stats will stay empty.\e[0m"
		fi
	fi
	rm -rf /etc/webalizer/webalizer.conf
fi

#--- Visitors (Visitor Stats module): not packaged on Ubuntu 26.04, built from the bundled 0.7 source
echo -e "\n-- Building Visitors"
if ! bash $PANEL_CONF/visitors/build-visitors.sh $PANEL_CONF/visitors; then
	echo -e "\n\e[1;31mWARNING: visitors did not build, Visitor Stats will stay empty.\e[0m"
fi
# Reports live outside the web root and are shown by the module to each domain's owner only
mkdir -p $PANEL_DATA/stats/visitors $PANEL_DATA/stats/webalizer
chmod 755 $PANEL_DATA/stats $PANEL_DATA/stats/visitors $PANEL_DATA/stats/webalizer

#--- Apache status page (Apache Status module): mod_status, reachable from this server only
a2enmod status > /dev/null

#--- Set some Sentora database entries using. setso and setzadmin (require PHP)
echo -e "\n-- Configuring Sentora"
zadminpassword=$(passwordgen);
setzadmin --set "$zadminpassword";
$PANEL_PATH/panel/bin/setso --set sentora_domain "$PANEL_FQDN"
$PANEL_PATH/panel/bin/setso --set server_ip "$PUBLIC_IP"

# if not release, set beta version in database
if [[ $(echo "$SENTORA_CORE_VERSION" | sed  's|.*-\(beta\).*$|\1|') = "beta"  ]] ; then
    $PANEL_PATH/panel/bin/setso --set dbversion "$SENTORA_CORE_VERSION"
fi

# Make the daemon to run/build vhosts file.
$PANEL_PATH/panel/bin/setso --set apache_changed "true"
php -q $PANEL_PATH/panel/bin/daemon.php

##
#--- Firewall ? SHOULD WE???
##

##
#--- Fail2ban - This should be standard with install. We need a module to help user with settings. Maybe soon!
##

##
#--- Logrotate
##

#  Download and install logrotate
echo -e "\n-- Installing Logrotate"
$PACKAGE_INSTALLER logrotate

#	Link the configfiles 
ln -s $PANEL_CONF/logrotate/Sentora-apache /etc/logrotate.d/Sentora-apache
ln -s $PANEL_CONF/logrotate/Sentora-proftpd /etc/logrotate.d/Sentora-proftpd
ln -s $PANEL_CONF/logrotate/Sentora-dovecot /etc/logrotate.d/Sentora-dovecot

#	Configure the postrotatesyntax for different OS
if [[ "$OS" = "Ubuntu" && "$VER" = "26.04" ]]; then
	sed -i 's|systemctl reload httpd > /dev/null|systemctl reload apache2 > /dev/null|' $PANEL_CONF/logrotate/Sentora-apache
elif [[ "$OS" = "Ubuntu" || "$OS" = "debian" ]]; then
	sed -i 's|systemctl reload httpd > /dev/null|/etc/init.d/apache2 reload > /dev/null|' $PANEL_CONF/logrotate/Sentora-apache
fi

#--- Resolv.conf deprotect
[ -L /etc/resolv.conf ] || chattr -f -i /etc/resolv.conf

#--- Lock down files that hold passwords
# Everything under $PANEL_PATH was made world-writable earlier (chmod -R 777),
# which included the MySQL root password and the service database passwords.
chown root:$HTTP_GROUP $PANEL_PATH/panel/cnf/db.php $PANEL_PATH/panel/cnf/security.php
chmod 640 $PANEL_PATH/panel/cnf/db.php $PANEL_PATH/panel/cnf/security.php
chown root:root $PANEL_CONF/proftpd/proftpd-mysql.conf
chmod 600 $PANEL_CONF/proftpd/proftpd-mysql.conf
chown root:postfix $PANEL_CONF/postfix/mysql-*.cf
chmod 640 $PANEL_CONF/postfix/mysql-*.cf
chmod 644 $PANEL_CONF/postfix/main.cf $PANEL_CONF/postfix/master.cf
chown root:root $PANEL_CONF/bind/rndc.conf 2> /dev/null; chmod 600 $PANEL_CONF/bind/rndc.conf 2> /dev/null
chmod 640 /etc/bind/rndc.conf 2> /dev/null
# The File Manager's own program files must not be writable by the web server
# (it edits users' files, never itself)
chown -R root:root $PANEL_PATH/panel/modules/file_manager
chmod -R u=rwX,go=rX $PANEL_PATH/panel/modules/file_manager
# Same for Apache Status: its settings are fixed files, nothing in it should be writable
chown -R root:root $PANEL_PATH/panel/modules/apache_status
chmod -R u=rwX,go=rX $PANEL_PATH/panel/modules/apache_status

## Leaving this just incase. ***
#--- Restart all services to capture output messages, if any
if [[ "$OS" = "CentOs" ]]; then
    # CentOs does not return anything except redirection to systemctl :-(
    service() {
       echo "Restarting $1"
       systemctl restart "$1.service"
    }
fi

# Clean up files needed for install/update
# N/A

echo -e "# -------------------------------------------------------------------------------"

# Set admin contact info to zadmin profile

echo -e "\n--- Updating Admin contact Info..."
mysql -u root -p"$mysqlpassword" -e "UPDATE sentora_core.x_accounts SET ac_email_vc='$ADMIN_EMAIL' WHERE sentora_core.x_accounts.ac_id_pk = 1"
mysql -u root -p"$mysqlpassword" -e "UPDATE sentora_core.x_profiles SET ud_fullname_vc='$ADMIN_NAME', ud_phone_vc='$ADMIN_PHONE', ud_address_tx='$ADMIN_ADDRESS\r\n$ADMIN_PROVINCE $ADMIN_POSTALCODE\r\n$ADMIN_COUNTRY', ud_postcode_vc='$ADMIN_POSTALCODE' WHERE sentora_core.x_profiles.ud_id_pk = 1"

echo -e "\n--- Done Updating admin contact info.\n"

echo -e "# -------------------------------------------------------------------------------"

# Package installs (such as MySQL) load their AppArmor profiles again on
# Ubuntu 26.04, unload them so Sentora's paths are not blocked.
if [[ "$OS" = "Ubuntu" && "$VER" = "26.04" ]] && command -v aa-teardown &> /dev/null; then
    aa-teardown &> /dev/null
fi

echo -e "\n--- Restarting Services"
echo -e "Restarting $DB_SERVICE..."
systemctl restart "$DB_SERVICE"
echo -e "Restarting $HTTP_SERVICE..."
systemctl restart "$HTTP_SERVICE"
echo -e "Restarting Postfix..."
systemctl restart postfix
echo -e "Restarting Dovecot..."
systemctl restart dovecot
echo -e "Restarting BIND..."
systemctl restart "$BIND_SERVICE"
echo -e "Restarting Proftpd..."
systemctl restart proftpd
echo -e "Restarting CRON..."
systemctl restart "$CRON_SERVICE"
echo -e "Restarting ATD..."
systemctl restart atd

echo -e "\n--- Finished Restarting Services...\n"

#--- Store the passwords for user reference
{
    echo "Server IP address : $PUBLIC_IP"
    echo "Panel URL         : https://$PANEL_FQDN"
    echo "zadmin Password   : $zadminpassword"
    echo ""
    echo "MySQL Root Password      : $mysqlpassword"
    echo "MySQL Postfix Password   : $postfixpassword"
    echo "MySQL Roundcube Password : $roundcubepassword"
    echo "MySQL ProFTPd Password   : $proftpdpassword"
} >> /root/passwords.txt
chmod 600 /root/passwords.txt

#--- Advise the admin that Sentora is now installed and accessible.
{
echo "########################################################"
echo " Congratulations Sentora has now been installed on your"
echo " server. Please review the log file left in /root/ for "
echo " any errors encountered during installation."
echo ""
echo " Login to Sentora at https://$PANEL_FQDN"
echo " Sentora Username  : zadmin"
echo " Sentora Password  : $zadminpassword"
echo ""
echo " MySQL Root Password      : $mysqlpassword"
echo " MySQL Postfix Password   : $postfixpassword"
echo " MySQL Roundcube Password : $roundcubepassword"
echo " MySQL ProFTPd Password   : $proftpdpassword"
echo "   (theses passwords are saved in /root/passwords.txt)"
echo "########################################################"
echo ""
} &>/dev/tty

# Wait until the user have read before restarts the server...
if [[ "$INSTALL" != "auto" ]] ; then
    while true; do
        read -r -e -p "Restart your server now to complete the install (y/n)? " rsn
        case $rsn in
            [Yy]* ) break;;
            [Nn]* ) exit;
        esac
    done
    shutdown -r now
fi
