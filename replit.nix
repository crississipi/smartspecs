{ pkgs }: {
  deps = [
    pkgs.php82
    pkgs.php82Packages.composer
    pkgs.python311
    pkgs.python311Packages.pip
    pkgs.mysql80
    pkgs.curl
    pkgs.git
    pkgs.bash
    pkgs.procps  # for process management (ps, kill, etc.)
  ];
  
  env = {
    PHP_INI_SCAN_DIR = "${pkgs.php82}/etc";
    PYTHONBIN = "${pkgs.python311}/bin/python3.11";
    PYTHON_LD_LIBRARY_PATH = pkgs.lib.makeLibraryPath [
      pkgs.stdenv.cc.cc.lib
    ];
    PYTHONHOME = "${pkgs.python311}";
    LANG = "en_US.UTF-8";
  };
}
