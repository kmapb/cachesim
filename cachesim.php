<?hh

require_once '/home/kma/php/filereader.php';
require_once '/home/kma/php/dprintf.php';

class Line {
  public int $lruAge;
  public int $address;
  const LineSize = 64;
  const LineShift = 6;
  function __construct ($addr, $age) {
    $this->lruAge = $age;
    $this->address = $addr & ~63;
  }
  /*
   * Bring this entry into the cache. Returns true, and updates the touch
   * count, if it was already present.
   */
  function touch($addr, $gen) : bool {
    dprintf("probe: %x vs. %x, shifted %x %x \n",
           $addr, $this->address,
           $addr >> Line::LineShift, $this->address >> Line::LineShift);
    if (($addr >> Line::LineShift) == ($this->address >> Line::LineShift)) {
      $this->lruAge = $gen;
      return true;
    }
    return false;
  }

  function pretty() : string {
    return sprintf("0x%x ", $this->address, $this->lruAge);
  }
}

class Way {
  public int $generation;
  public int $assoc;
  public array $entries;

  function __construct(int $assoc) {
    $this->generation = 0;
    $this->assoc = $assoc;
    foreach (range(0, $assoc - 1) as $i) {
      $this->entries[] = new Line(0, 0);
    }
  }
  function touch($addr) : bool {
    $this->generation++;
    foreach ($this->entries as $e) {
      if ($e->touch($addr, $this->generation)) {
        dprintf("hit ! $addr\n");
        return true;
      }
    }
    if (isset($_ENV['VERBOSE'])) {
      $out = sprintf("missline 0x%x ", $addr);
      foreach ($this->entries as $e) {
        $out .= $e->pretty();
      }
      echo "$out\n";
    }
    // Drat! Need to replace.
    $minGen = $this->generation;
    $replace = rand() % $this->assoc;
    for ($i = 0; $i < $this->assoc; $i++) {
      dprintf("considering %d vs. %d\n", $this->entries[$i]->lruAge,
              $minGen);
      if ($this->entries[$i]->lruAge < $minGen) {
        $replace = $i;
        dprintf("yes, $replace!\n");
        $minGen = $this->entries[$i]->lruAge;
      }
    }
    dprintf("replacing %d\n", $replace);
    printf("evict 0x%x 0x%x newer ", $this->entries[$replace]->address, $addr);
    for ($i = 0; $i < $this->assoc; $i++) {
      if ($i != $replace) printf("0x%x ", $this->entries[$i]->address);
    }
    printf("\n");
    assert($replace < $this->assoc);
    $this->entries[$replace] = new Line($addr, $this->generation);
    dprintf("reprobe: %d\n", (int)$this->entries[$replace]->touch($addr,
                                                                 $this->generation));
  }
}

class Cache {
  private array $ways; // :: array(Ways)
  const NumWays = 64;
  const Assoc = 8;

  function __construct() {
    foreach (range(0, Cache::NumWays - 1) as $w) {
      $this->ways []= new Way(Cache::Assoc);
    }
  }

  private function addr2Way($addr) {
    $idx = ($addr >> 6) & 63;
    return $this->ways[$idx];
  }

  function touch(int $addr) : bool {
    return $this->addr2Way($addr)->touch($addr);
  }
}

function main() {
  $fr = new FileReader();
  $c = new Cache();
  $lineNum = 1;
  foreach ($fr->getLines() as $line) {
    $addr = (int)hexdec(trim($line));
    if (!$c->touch($addr)) {
      printf("miss %10d 0x%x\n", $lineNum, $addr);
    }
    $lineNum++;
  }
}

main();
