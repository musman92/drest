<?php
namespace Entities;

// uniqueConstraints={@UniqueConstraint(name="api_key_idx", columns={"api_key"})})

// Alternative
//  *      		expose={"username", "email_address", "profile" : {"id", "lastname", "addresses" : {"address"}}, "phone_numbers" : {"number"}}
// Use short expose syntax in http headers / request params:  username|email_address|profile[id|lastname|addresses[id]]|phone_numbers
// service_call={"Service\User", "getMyCustomElement"}

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Drest\Mapping\Annotation as Drest;
use DrestCommon\Request\Request;

/**
 * User
 *
 * @ORM\Table(name="user")
 * @ORM\Entity
 */
class User
{

    /**
     * @var integer $id
     *
     * @ORM\Column(name="id", type="integer", length=4)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var \Entities\Profile $profile
     * @ORM\OneToOne(targetEntity="Profile", mappedBy="user", cascade={"persist", "remove"}, fetch="EAGER")
     */
    private $profile;

    /**
     * @var ArrayCollection $phone_numbers
     * @ORM\OneToMany(targetEntity="PhoneNumber", mappedBy="user", cascade={"persist", "remove"}, fetch="EAGER")
     */
    private $phone_numbers;

    /**
     * @var string $username
     * @ORM\Column(name="username", type="string", length=255)
     */
    private $username;

    /**
     * @var string $email_address
     * @ORM\Column(name="email_address", type="string", length=255)
     */
    private $email_address;


    public function __construct()
    {
        $this->phone_numbers = new ArrayCollection();
    }

    /**
     * Get the Id
     * @return integer $id
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     */
    public function populatePost(array $data, Request $request)
    {
        if (isset($data['email_address'])) {
            $this->email_address = $data['email_address'];
        }
        if (isset($data['username'])) {
            $this->username = $data['username'];
        }
        if (isset($data['phone_numbers']) && is_array($data['phone_numbers'])) {
            foreach ($data['phone_numbers'] as $phone_number) {
                $pn = new PhoneNumber();
                $pn->setNumber($phone_number['number']);
                $this->addPhoneNumber($pn);
            }
        }
    }

    public function addPhoneNumber(PhoneNumber $phoneNumber)
    {
        $phoneNumber->setUser($this);
        $this->phone_numbers->add($phoneNumber);
    }

    /**
     */
    public function patchUser(array $data)
    {
        $this->username = $data['username'];
    }


}