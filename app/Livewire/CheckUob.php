<?php

namespace App\Livewire;

use App\Exports\CheckLists;
use App\Models\Aprcpit;
use App\Models\Aptrn;
use App\Models\Bktrn;
use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use XBase\TableReader;
use Illuminate\Support\Facades\Http;
use Livewire\Component;
use App\Services\DbfDataFetcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan as FacadesArtisan;
use Maatwebsite\Excel\Facades\Excel;

class CheckUob extends Component
{
    public $linkedData;
    public $dateCheck;
    public $date;
    public $ebill_to = [];

    public function mount()
    {
    $this->linkedData = collect(); // Initialize as an empty collection
    }

    // public function updateName($id){
    //     // dd($this->ebill_to[$id]);
    //     $this->linkedData->find($id)->apmas->supnam = $this->ebill_to[$id];  
    //     dd($this->linkedData);
    // }
    public function synD(){
        FacadesArtisan::call('dbf:sync');
    }

    public function getData(){
        $this->ebill_to = [];
        $selectedDate = Carbon::parse($this->dateCheck);
        $cmpdate = $selectedDate->format('Y').$selectedDate->format('m').$selectedDate->format('d');
        $this->linkedData = Bktrn::where('chqdat',$cmpdate)
            ->where('voucher','like','PS'.'%')
            ->with('aprcpit')->get();

        foreach ($this->linkedData as $data) {
            $Amount = 0;
            $total= 0;
            foreach($data->aprcpit as $aprcpit){
              $Amount += $aprcpit->aptrn->netamt ?? 0;
              $total+= $aprcpit->aptrn->amount ?? 0;
            }
            $this->ebill_to['taxid'][$data->id] = $data->apmas->taxid ?? null;
            $this->ebill_to['name'][$data->id] = ($data->apmas->prenam ?? null) . ($data->apmas->supnam ?? null);
            if($data->amount == $Amount){
                $this->ebill_to['addr1'][$data->id] = null;
                $this->ebill_to['addr2'][$data->id] = null;
                $this->ebill_to['addr3'][$data->id] = null;
            }
            else{
                $this->ebill_to['addr1'][$data->id] = $data->apmas->addr01 ?? null;
                $this->ebill_to['addr2'][$data->id] = $data->apmas->addr02 ?? null;
                $this->ebill_to['addr3'][$data->id] = $data->apmas->addr03 ?? null;
            }
        }
    }  
    public function txtFile(){
        $selectedDate = Carbon::parse($this->dateCheck);
        $date = $selectedDate->format('d').$selectedDate->format('m').$selectedDate->format('Y');
        $output = null;
        foreach($this->linkedData as $data)          
        {
            $Amount = 0;
            $total= 0;
            foreach($data->aprcpit as $aprcpit){
              $Amount += $aprcpit->aptrn->netamt ?? 0;
              $total+= $aprcpit->aptrn->amount ?? 0;
            }
        //    dd($Amount - $data->amount);
           $fullAddress = mb_substr($this->ebill_to['addr1'][$data->id]
                . $this->ebill_to['addr2'][$data->id] 
                . $this->ebill_to['addr3'][$data->id],0,105); 
            $totalAmount = 0;
            $totalVat= 0;
            $totalNet= 0;
            $nameCom = mb_substr($this->ebill_to['name'][$data->id],0,70);
            $nameCom35 = mb_substr($this->ebill_to['name'][$data->id],0,35); 
            $address1 = mb_substr($this->ebill_to['addr1'][$data->id],0,35);
            $address2 = mb_substr($this->ebill_to['addr2'][$data->id],0,35);
            $address3 = mb_substr($this->ebill_to['addr3'][$data->id],0,35);
            $taxDes = mb_substr($data->apmas->taxdes ?? 0,0,35);
            $output .= 
            str_pad('TXN',3)  
            // .str_pad(mb_strlen($nameCom),2)
            .$nameCom
            .str_pad("",70-mb_strlen($nameCom))
            .$nameCom35
            .str_pad("",35-mb_strlen($nameCom35))
            .$address1
            .str_pad("",35-mb_strlen($address1))
            .$address2
            .str_pad("",35-mb_strlen($address2))
            .$address3
            .str_pad("",35-mb_strlen($address3))
            .str_pad($data->apmas->zipcod ?? null,10)
            .str_pad(sprintf('%015.2f',$data->netamt ?? null),15)
            .str_pad($data->chqnum ?? null,15)
            .str_pad($date,8)
            .str_pad("REC+TAX",35)
            .str_pad("",35)
            .str_pad("",35)
            .str_pad("RT",2)
            .str_pad("SATHORN1",15)
            .str_pad("",5)
            .str_pad('',10)
            .str_pad('',70)
            .str_pad('OUR',12)
            .str_pad(sprintf('%013.0f',$this->ebill_to['taxid'][$data->id]),13)
            .str_pad('53',2)
            .str_pad(sprintf('%015.2f',$total),15)
            .str_pad($data->apmas->suptyp ?? null,2)
            .$taxDes
            .str_pad('',35-mb_strlen($taxDes));
            if($data->amount == $Amount){
                $output .= str_pad('',20);
            }
            else{
                $output .= str_pad(sprintf('%05.2f',$data->apmas->taxrat ?? null),5)
                .str_pad(sprintf('%015.2f',round($Amount - $data->amount ?? 0,2),15),15);
            }
           $output  .= 
            str_pad(sprintf('%015.2f',''),15)
            .str_pad('',35)
            .str_pad('',2)
            .str_pad(sprintf('%05.2f',''),5)
            .str_pad(sprintf('%015.2f',''),15)
            .str_pad(sprintf('%015.2f',''),15)
            .str_pad('',35)
            .str_pad('',2)
            .str_pad(sprintf('%05.2f',''),5)
            .str_pad(sprintf('%015.2f',''),15)
            .str_pad("",15)
            .$nameCom35
            .str_pad("",35-mb_strlen($nameCom35)) 
            .$fullAddress
            .str_pad("",105-mb_strlen($fullAddress))
            .str_pad($data->apmas->taxcond ?? null,1)
            ."\n".
            // str_pad('',3).
            str_pad('INV No.',14).
            str_pad('',1).
            str_pad('Inv. Date',10).
            str_pad('',2).
            str_pad('Inv.Amt',13,' ', STR_PAD_LEFT).
            str_pad('',2).
            str_pad('Vat',14,' ', STR_PAD_LEFT).
            str_pad('',6).
            str_pad('Net',11,' ', STR_PAD_LEFT)
            ."\n";
            foreach($data->aprcpit as $aprcpit){
              $totalAmount += $aprcpit->aptrn->amount ?? 0;
              $totalVat += $aprcpit->aptrn->vatamt ?? 0;
              $totalNet += $aprcpit->aptrn->netamt ?? 0;
              $dateinv = substr($aprcpit->aptrn->duedat, 6, 2) . "/" . substr($aprcpit->aptrn->duedat, 4, 2) . "/" . substr($aprcpit->aptrn->duedat, 0, 4); 
           $output .=
            // str_pad('',3).
            str_pad('INV'.($aprcpit->aptrn->refnum ?? null),18).
            str_pad('',1).
            str_pad($dateinv,10).
            str_pad('',2).
            str_pad(sprintf('%.2f',$aprcpit->aptrn->amount ?? 0),11,' ', STR_PAD_LEFT).
            str_pad('',2).
            str_pad(sprintf('%.2f',$aprcpit->aptrn->vatamt ?? 0),9,' ', STR_PAD_LEFT).
            str_pad(sprintf('%.2f',$aprcpit->aptrn->netamt ?? 0),13,' ', STR_PAD_LEFT)
            ."\n"; 
            }
            $output .= 
            // str_pad('',3).
            str_pad('INV',15).
            "\n". 
            // str_pad('',3).
            str_pad('INV Total',15).
            str_pad('',1).
            str_pad('',10).
            str_pad('',2).
            str_pad(sprintf('%.2f',$totalAmount),14,' ', STR_PAD_LEFT).
            str_pad('',2).
            str_pad(sprintf('%.2f',$totalVat),9,' ', STR_PAD_LEFT).
            str_pad(sprintf('%.2f',$totalNet),13,' ', STR_PAD_LEFT)
            ."\n".
            str_pad('INV =====================================================================',73)."\n";
        } 

        $ansiData = iconv('UTF-8', 'Windows-874//TRANSLIT', $output);

    // Ensure CRLF line endings for PC format
        $data= str_replace("\n", "\r\n", $ansiData);
        Storage::disk('local')->put('test.txt',$data);
        return response()->download(storage_path('app/test.txt'),'test.txt');
    }
    public function exportExcel(){
       return Excel::download(new CheckLists($this->linkedData,$this->ebill_to),'report.xlsx');
    }

    public function cu27(){
        $selectedDate = Carbon::parse($this->dateCheck);
        $date = $selectedDate->format('d').$selectedDate->format('m').$selectedDate->format('Y');
        $output = null;   
        foreach($this->linkedData as $data){
            $Amount = 0;
            $total= 0;
            foreach($data->aprcpit as $aprcpit){
              $Amount += $aprcpit->aptrn->netamt ?? 0;
              $total+= $aprcpit->aptrn->amount ?? 0;
            }
            $totalAmount = 0;
            $totalNet = 0;
            $totalVat = 0;
            $fullAddress = mb_substr($this->ebill_to['addr1'][$data->id]
                . $this->ebill_to['addr2'][$data->id] 
                . $this->ebill_to['addr3'][$data->id],0,105); 
            $nameCom35 = mb_substr($this->ebill_to['name'][$data->id],0,35);
            $nameCom = mb_substr($this->ebill_to['name'][$data->id],0,150);
            $address1 = mb_substr($this->ebill_to['addr1'][$data->id],0,70);
            $address2 = mb_substr($this->ebill_to['addr2'][$data->id],0,70);
            $address3 = mb_substr($this->ebill_to['addr3'][$data->id],0,70);
            $taxDes = mb_substr($data->apmas->taxdes ?? null,0,35);
            $output .= str_pad('TXN',3).
                    (str_pad('CHC',10)).
                    $nameCom.
                    str_pad("",150-mb_strlen($nameCom)).
                    $address1.
                    str_pad("",70-mb_strlen($address1)).
                    $address2.
                    str_pad("",70-mb_strlen($address2)).
                    $address3.
                    str_pad("",70-mb_strlen($address3)).
                    str_pad("",70).
                    str_pad("",70).
                    $nameCom.
                    str_pad("",150-mb_strlen($nameCom)).
                    $address1.
                    str_pad("",70-mb_strlen($address1)).
                    $address2.
                    str_pad("",70-mb_strlen($address2)).
                    $address3.
                    str_pad("",70-mb_strlen($address3)). 
                    str_pad("",70).
                    str_pad($data->apmas->zipcod ?? null,10).
                    str_pad("",20).
                    str_pad("",20).
                    str_pad("",20).
                    str_pad("",20).
                    str_pad(sprintf('%020.2f',$data->netamt),20).
                    str_pad("THB",10).
                    str_pad($data->chqnum,35).
                    str_pad($date,8).
                    str_pad("",35).
                    str_pad("",35).
                    str_pad("",35).
                    str_pad("",35).
                    str_pad("REC+TAX",35).
                    str_pad("RT",5).
                    str_pad("SATHORN1",35).
                    str_pad("",5).
                    str_pad("",10).
                    str_pad("",120).
                    str_pad("",10).
                    str_pad("",20).
                    str_pad("OUR",12).
                    str_pad(sprintf('%020.0f',$data->apmas->taxid ?? null),20).
                    str_pad("",20).
                    str_pad('53',5)
                    .str_pad(sprintf('%020.2f',$data->amount),20)
                    .str_pad($data->apmas->suptyp ?? null,2);
                    if($data->amount == $Amount){
                        $output .= str_pad('',35) 
                                .str_pad('00.00',5)
                                .str_pad('000000000000.00',15);
                    }
                    else{
                        $output .= $taxDes
                            .str_pad('',35-mb_strlen($taxDes))
                            .str_pad(sprintf('%05.2f',$data->apmas->taxrat ?? null),5)
                            .str_pad(sprintf('%015.2f',round($Amount - $data->amount ?? 0,2),15),15);
                    }
                    $output .= str_pad(sprintf('%020.2f',''),20)
                    .str_pad('',35)
                    .str_pad('',2)
                    .str_pad(sprintf('%05.2f',''),5)
                    .str_pad(sprintf('%020.2f',''),20)
                    .str_pad(sprintf('%020.2f',''),20)
                    .str_pad('',35)
                    .str_pad('',2)
                    .str_pad(sprintf('%05.2f',''),5)
                    .str_pad(sprintf('%015.2f',''),15)
                    .str_pad("",15)
                    .str_pad('',159)
                    .str_pad("",20)
                    .$nameCom35
                    .str_pad("",35-mb_strlen($nameCom35)) 
                    .$fullAddress
                    .str_pad("",105-mb_strlen($fullAddress))
                    .str_pad("",10)
                    .str_pad('1',1)
                    ."\n";
            //            str_pad('INV No.',14).
            // str_pad('',1).
            // str_pad('Inv. Date',10).
            // str_pad('',2).
            // str_pad('Inv.Amt',13,' ', STR_PAD_LEFT).
            // str_pad('',2).
            // str_pad('Vat',14,' ', STR_PAD_LEFT).
            // str_pad('',6).
            // str_pad('Net',11,' ', STR_PAD_LEFT)
            // ."\n";

            foreach($data->aprcpit as $aprcpit){
              $totalAmount += $aprcpit->aptrn->amount ?? 0;
              $totalVat += $aprcpit->aptrn->vatamt ?? 0;
              $totalNet += $aprcpit->aptrn->netamt ?? 0;
              $dateinv = substr($aprcpit->aptrn->duedat, 6, 2) . "/" . substr($aprcpit->aptrn->duedat, 4, 2) . "/" . substr($aprcpit->aptrn->duedat, 0, 4); 
           $output .=
            // str_pad('',3).
            str_pad('INV'.($aprcpit->aptrn->refnum ?? null),18).
            str_pad('',1).
            str_pad($dateinv,10).
            str_pad('',2).
            str_pad(sprintf('%.2f',$aprcpit->aptrn->amount ?? 0),11,' ', STR_PAD_LEFT).
            str_pad('',2).
            str_pad(sprintf('%.2f',$aprcpit->aptrn->vatamt ?? 0),9,' ', STR_PAD_LEFT).
            str_pad(sprintf('%.2f',$aprcpit->aptrn->netamt ?? 0),13,' ', STR_PAD_LEFT)
            ."\n"; 
            }
        }
        $ansiData = iconv('UTF-8', 'Windows-874//TRANSLIT', $output);
        $data= str_replace("\n", "\r\n", $ansiData);
        Storage::disk('local')->put('test.txt',$data);
        return response()->download(storage_path('app/test.txt'),'cu27.txt');
    }
    public function render()
    {
        return view('livewire.check-uob');
        
    }
}
